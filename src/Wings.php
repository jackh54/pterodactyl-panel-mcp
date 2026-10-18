<?php

namespace PanelMcp;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Support\Facades\App;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Daemon\DaemonConnection;
use Psr\Http\Message\ResponseInterface;

/**
 * Two Wings calls the panel has no client method for: the state of every server on a node in
 * one request, and streaming a file to disk with a size cap (the panel's file client holds the
 * whole body in memory).
 */
class Wings
{
    /**
     * State and resource use of every server on the node, keyed by server UUID.
     *
     * @return array<string, array{state: string, utilization: array}>
     *
     * @throws DaemonConnectionException
     */
    public function nodeStates(Node $node): array
    {
        $response = DaemonConnection::forNode($node)->request('GET', '/api/servers', useStatusCode: false);
        $items = json_decode($response->body(), true);

        $out = [];
        foreach (is_array($items) ? $items : [] as $item) {
            $uuid = is_array($item) ? ($item['configuration']['uuid'] ?? null) : null;
            if (is_string($uuid)) {
                $out[$uuid] = [
                    'state' => is_string($item['state'] ?? null) ? $item['state'] : 'offline',
                    'utilization' => is_array($item['utilization'] ?? null) ? $item['utilization'] : [],
                ];
            }
        }

        return $out;
    }

    /**
     * Streams a server file to a local file without holding it in memory. Returns the byte
     * count. Throws \OverflowException when the file is larger than $maxBytes.
     *
     * @throws ToolException
     */
    public function downloadTo(Server $server, string $path, string $sink, int $maxBytes): int
    {
        $node = $server->node;
        $client = new Client([
            'base_uri' => $node->getConnectionAddress(),
            'timeout' => 120,
            'connect_timeout' => (int) config('pterodactyl.guzzle.connect_timeout', 5),
            'verify' => App::isProduction(),
            'http_errors' => false,
            'headers' => [
                'Authorization' => 'Bearer '.$node->getDecryptedKey(),
                'Accept' => 'application/json',
            ],
        ]);

        try {
            $response = $client->get(sprintf('/api/servers/%s/files/contents', $server->uuid), [
                'query' => ['file' => $path],
                'sink' => $sink,
                // Stop as soon as the size is known to be too big, instead of fetching it all.
                'on_headers' => function (ResponseInterface $response) use ($maxBytes) {
                    if ($response->getStatusCode() < 300 && (int) $response->getHeaderLine('Content-Length') > $maxBytes) {
                        throw new \OverflowException('too large');
                    }
                },
            ]);
        } catch (RequestException $exception) {
            if ($exception->getPrevious() instanceof \OverflowException) {
                @unlink($sink);
                throw new \OverflowException('too large');
            }
            throw new ToolException('Could not establish a connection to the machine running this server. Please try again.');
        } catch (TransferException) {
            throw new ToolException('Could not establish a connection to the machine running this server. Please try again.');
        }

        $status = $response->getStatusCode();
        if ($status >= 400) {
            $body = json_decode((string) @file_get_contents($sink, false, null, 0, 65536), true);
            @unlink($sink);
            $error = is_array($body) && is_string($body['error'] ?? null) ? $body['error'] : null;

            throw new ToolException($status < 500 && $error !== null
                ? "An error occurred on the remote host: {$error}."
                : "There was an error while communicating with the machine running this server (code: {$status}).");
        }

        clearstatcache(true, $sink);
        $size = (int) filesize($sink);
        if ($size > $maxBytes) {
            @unlink($sink);
            throw new \OverflowException('too large');
        }

        return $size;
    }
}
