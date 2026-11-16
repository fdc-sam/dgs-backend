{{-- Any other 5xx. --}}
@extends('errors.layout')

@section('code', isset($exception) ? $exception->getStatusCode() : 500)
@section('title', 'Something went wrong')
@section('message', 'It’s not you, it’s us. Please try again in a few minutes.')
@section('action', 'retry')
