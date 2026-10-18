import { cleanup, render } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import ConnectedAppsCard from './ConnectedAppsCard';

afterEach(() => cleanup());

describe('ConnectedAppsCard', () => {
    it('links to the server-rendered connections page with a full page load', () => {
        const { getByRole } = render(<ConnectedAppsCard />);
        const link = getByRole('link', { name: /manage connected apps/i });
        expect(link.getAttribute('href')).toBe('/oauth/connections');
        expect(link.getAttribute('target')).toBeNull();
    });

    it('uses only this extension\'s utility prefix', () => {
        const { container } = render(<ConnectedAppsCard />);
        const classes = Array.from(container.querySelectorAll('[class]')).flatMap((el) => Array.from(el.classList));
        const utilities = classes.filter((c) => c.includes(':'));
        expect(utilities.length).toBeGreaterThan(0);
        expect(utilities.every((c) => c.startsWith('pmcp:'))).toBe(true);
    });
});
