import { PlugZap } from 'lucide-react';
import { ContentBox, Icon } from '@pterodactyl/sdk';

/** Account overview: a card linking to the server-rendered /oauth/connections page. */
export default function ConnectedAppsCard() {
    return (
        <div className={'panel-mcp-connected-apps pmcp:mb-10 pmcp:grid pmcp:grid-cols-1 pmcp:gap-8 pmcp:lg:grid-cols-3'}>
            <ContentBox title={'Connected Apps'}>
                <p className={'pmcp:text-sm'}>
                    Apps you have allowed to use your panel account, such as an AI assistant connected through the
                    panel&apos;s MCP server. Review what each one can do, or disconnect it.
                </p>
                <div className={'pmcp:mt-6'}>
                    {/* A full page load: the connections page is rendered by the server, not the SPA. */}
                    <a
                        href={'/oauth/connections'}
                        className={
                            'pmcp:inline-flex pmcp:items-center pmcp:gap-2 pmcp:rounded-sm pmcp:border pmcp:border-primary pmcp:bg-primary pmcp:px-4 pmcp:py-2 pmcp:text-sm pmcp:uppercase pmcp:tracking-wide pmcp:text-primary-foreground pmcp:no-underline pmcp:transition-colors pmcp:hover:bg-primary/80'
                        }
                    >
                        <Icon icon={PlugZap} /> Manage connected apps
                    </a>
                </div>
            </ContentBox>
        </div>
    );
}
