import './bootstrap';

// Self-hosted fonts (bundled by Vite): no third-party font CDN, CSP stays 'self'.
import '@fontsource-variable/inter';
import '@fontsource/jetbrains-mono/500.css';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.start();
