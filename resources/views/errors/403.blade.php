@extends('errors.layout')

@section('code', '403')
@section('icon', 'lock')
@section('title', __('Access denied'))
@section('message', __('Your account does not have permission to open this page.'))
