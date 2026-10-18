import './styles.css';
import { definePterodactylExtension } from '@pterodactyl/sdk';
import ConnectedAppsCard from './ConnectedAppsCard';

// setup() must stay synchronous.
export default definePterodactylExtension({
    setup(ctx) {
        // Account overview, below the panel's own cards.
        ctx.slots.register('account.overview.after', ConnectedAppsCard);
    },
});
