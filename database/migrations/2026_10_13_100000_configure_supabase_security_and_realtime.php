<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Supabase-only setup (no-op on MySQL or a plain local Postgres):
 *
 * 1. Lock down the Data API. Supabase exposes the `public` schema over REST to the `anon` and
 *    `authenticated` roles, and the publishable key ships inside the mobile app. Laravel connects as
 *    `postgres` (BYPASSRLS), so revoking those roles' privileges and enabling RLS everywhere costs nothing.
 * 2. In-app notifications over Realtime: a customer may SELECT only their own `notifications` rows,
 *    identified by the `sub` claim of the short-lived JWT Laravel issues (RealtimeTokenService).
 * 3. Push: every inserted notification is handed to the `send-push` Edge Function via pg_net
 *    (async — never slows or fails the insert). URL + shared secret live in Vault (dgs:supabase-push).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->isSupabase()) {
            return;
        }

        DB::unprepared(<<<'SQL'
            -- 1. Data API lockdown (existing + future objects created by postgres)
            REVOKE ALL ON ALL TABLES IN SCHEMA public FROM anon, authenticated;
            REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM anon, authenticated;
            ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public REVOKE ALL ON TABLES FROM anon, authenticated;
            ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public REVOKE ALL ON SEQUENCES FROM anon, authenticated;
            ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public REVOKE EXECUTE ON FUNCTIONS FROM anon, authenticated, PUBLIC;

            DO $$
            DECLARE t record;
            BEGIN
                FOR t IN SELECT tablename FROM pg_tables WHERE schemaname = 'public' LOOP
                    EXECUTE format('ALTER TABLE public.%I ENABLE ROW LEVEL SECURITY', t.tablename);
                END LOOP;
            END $$;

            -- 2. Realtime: customers read their own notifications only
            GRANT USAGE ON SCHEMA public TO authenticated;
            GRANT SELECT ON public.notifications TO authenticated;
            DROP POLICY IF EXISTS "own notifications" ON public.notifications;
            CREATE POLICY "own notifications" ON public.notifications
                FOR SELECT TO authenticated
                USING (notifiable_type = 'user' AND notifiable_id = (SELECT auth.jwt() ->> 'sub'));

            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_publication_tables
                    WHERE pubname = 'supabase_realtime' AND schemaname = 'public' AND tablename = 'notifications'
                ) THEN
                    ALTER PUBLICATION supabase_realtime ADD TABLE public.notifications;
                END IF;
            END $$;

            -- 3. Push dispatch via pg_net → Edge Function
            CREATE EXTENSION IF NOT EXISTS pg_net WITH SCHEMA extensions;

            CREATE OR REPLACE FUNCTION public.dgs_dispatch_push()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = ''
            AS $fn$
            DECLARE
                v_url text;
                v_secret text;
            BEGIN
                SELECT decrypted_secret INTO v_url FROM vault.decrypted_secrets WHERE name = 'dgs_push_function_url';
                SELECT decrypted_secret INTO v_secret FROM vault.decrypted_secrets WHERE name = 'dgs_push_webhook_secret';
                IF v_url IS NULL OR v_secret IS NULL OR NEW.notifiable_type <> 'user' THEN
                    RETURN NEW;
                END IF;

                PERFORM net.http_post(
                    url := v_url,
                    headers := jsonb_build_object('Content-Type', 'application/json', 'x-dgs-push-secret', v_secret),
                    body := jsonb_build_object('notification_id', NEW.id),
                    timeout_milliseconds := 5000
                );
                RETURN NEW;
            END;
            $fn$;

            REVOKE ALL ON FUNCTION public.dgs_dispatch_push() FROM PUBLIC, anon, authenticated;

            DROP TRIGGER IF EXISTS dgs_notifications_push ON public.notifications;
            CREATE TRIGGER dgs_notifications_push
                AFTER INSERT ON public.notifications
                FOR EACH ROW EXECUTE FUNCTION public.dgs_dispatch_push();
        SQL);
    }

    public function down(): void
    {
        if (! $this->isSupabase()) {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS dgs_notifications_push ON public.notifications;
            DROP FUNCTION IF EXISTS public.dgs_dispatch_push();
            DROP POLICY IF EXISTS "own notifications" ON public.notifications;
            REVOKE SELECT ON public.notifications FROM authenticated;
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_publication_tables WHERE pubname = 'supabase_realtime' AND tablename = 'notifications') THEN
                    ALTER PUBLICATION supabase_realtime DROP TABLE public.notifications;
                END IF;
            END $$;
        SQL);
        // The Data API lockdown is intentionally kept.
    }

    private function isSupabase(): bool
    {
        return DB::getDriverName() === 'pgsql'
            && DB::selectOne("SELECT EXISTS (SELECT 1 FROM pg_namespace WHERE nspname = 'auth') AS a")->a
            && DB::selectOne("SELECT EXISTS (SELECT 1 FROM pg_publication WHERE pubname = 'supabase_realtime') AS p")->p
            && DB::selectOne("SELECT EXISTS (SELECT 1 FROM pg_namespace WHERE nspname = 'vault') AS v")->v;
    }
};
