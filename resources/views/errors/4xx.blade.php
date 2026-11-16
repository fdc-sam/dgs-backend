{{-- Any other 4xx. A message written for people with abort(…, 'message') is shown; framework messages are not. --}}
@extends('errors.layout')

@php
    $status = isset($exception) ? $exception->getStatusCode() : 400;
    $ownMessage = isset($exception) && $exception::class === \Symfony\Component\HttpKernel\Exception\HttpException::class
        ? $exception->getMessage() : '';
@endphp

@section('code', $status)
@section('title', 'We couldn’t open this page')
@section('message', filled($ownMessage) ? $ownMessage : 'Something about this request wasn’t right. Please go back and try again.')
