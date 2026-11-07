<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * Signed-in shell: sidebar navigation, page header and flash messages.
 *
 * <x-app-layout title="Tickets" subtitle="…"> with optional slots
 * `actions` (header buttons) and `header` (replaces the default header).
 */
class AppLayout extends Component
{
    public function __construct(
        public ?string $title = null,
        public ?string $subtitle = null,
        public string $width = 'wide',
    ) {}

    public function render(): View
    {
        return view('layouts.app');
    }
}
