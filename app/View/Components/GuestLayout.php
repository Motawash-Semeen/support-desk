<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * Signed-out shell for the authentication screens: brand panel on the left,
 * form card on the right. <x-guest-layout title="Sign in" subtitle="…">.
 */
class GuestLayout extends Component
{
    public function __construct(
        public ?string $title = null,
        public ?string $subtitle = null,
    ) {}

    public function render(): View
    {
        return view('layouts.guest');
    }
}
