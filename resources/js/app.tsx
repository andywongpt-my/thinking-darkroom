import '../css/app.css';
import './bootstrap';

import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import route from './ziggy-route';
import { mountGuestRegistry } from './webmcp/guest-registry';

// The Ziggy route() helper is bundled with the app (see config/ziggy.php:
// skip-route-function). `@routes` supplies the route payload as window.Ziggy.
window.route = route;

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

type PageWithAuth = {
    props: {
        auth?: {
            user?: unknown;
        };
    };
};

function pageUser(page: PageWithAuth): unknown {
    return page.props.auth?.user;
}

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.tsx`,
            import.meta.glob('./Pages/**/*.tsx'),
        ),
    setup({ el, App, props }) {
        const root = createRoot(el);
        let cleanupGuestRegistry: (() => void) | null = null;

        if (pageUser(props.initialPage as PageWithAuth) == null) {
            // Nullish check (AGY L-2): a page that omits the auth prop entirely
            // still resolves as a guest and must get the guest registry.
            cleanupGuestRegistry = mountGuestRegistry();
        }

        router.on('navigate', (event) => {
            const user = pageUser(event.detail.page as PageWithAuth);

            if (user !== null && user !== undefined) {
                cleanupGuestRegistry?.();
                cleanupGuestRegistry = null;
            } else if (user === null && cleanupGuestRegistry === null) {
                cleanupGuestRegistry = mountGuestRegistry();
            }
        });

        root.render(<App {...props} />);
    },
    progress: {
        color: '#4B5563',
    },
});
