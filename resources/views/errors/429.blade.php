@extends('errors.layout')

@section('code', '429')
@section('icon', 'clock')
@section('title', __('Slow down a little'))
@section('message', __('Too many requests in a short time. Please wait a minute and try again.'))
