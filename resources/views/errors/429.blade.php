@extends('errors.layout')

@section('code', '429')
@section('title', 'Too many tries')
@section('message', 'You’ve tried a few too many times. Please wait a minute, then try again.')
@section('action', 'retry')
