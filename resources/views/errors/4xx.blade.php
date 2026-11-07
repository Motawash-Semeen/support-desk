{{-- Fallback for any 4xx status without its own page (400, 401, 405, 410, …). --}}
@php($code = isset($exception) && method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : 400)

@extends('errors.layout')

@section('code', (string) $code)
@section('icon', 'alert')
@section('title', \Symfony\Component\HttpFoundation\Response::$statusTexts[$code] ?? __('Request error'))
@section('message', __('The request could not be completed. Check the address, or go back and try again.'))
