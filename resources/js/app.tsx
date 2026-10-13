import { createInertiaApp } from '@inertiajs/react';
import type { ResolvedComponent } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import { initializeColorTheme } from '@/hooks/use-color-theme';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

const appPages = import.meta.glob<{ default: ResolvedComponent }>(
    './pages/**/*.tsx',
);
const modulePages = import.meta.glob<{ default: ResolvedComponent }>(
    '../../Modules/*/resources/js/pages/**/*.tsx',
);
const pages = { ...appPages, ...modulePages };

const resolvePage = async (name: string) => {
    const appPath = `./pages/${name}.tsx`;
    const modulePath = Object.keys(modulePages).find((path) =>
        path.endsWith(`/resources/js/pages/${name}.tsx`),
    );

    const module = await resolvePageComponent(modulePath ?? appPath, pages);

    return module.default;
};

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: resolvePage,
    layout: (name) => {
        switch (true) {
            case name === 'welcome':
            case name.startsWith('skills/'):
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();

// This will apply the stored color theme on load...
initializeColorTheme();
