{{-- Fallback for any 5xx status without its own page (502, 504, …). --}}
@php($code = isset($exception) && method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : 500)

@extends('errors.layout')

@section('code', (string) $code)
@section('icon', 'alert')
@section('title', __('Something went wrong'))
@section('message', __('The server could not complete the request. The error has been logged; please try again in a moment.'))
