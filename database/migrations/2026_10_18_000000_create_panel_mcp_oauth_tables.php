<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OAuth 2.1 storage for the MCP endpoint (/mcp).
 *
 * clients  = apps that registered themselves (dynamic client registration, public clients).
 * grants   = one "connection": a user approved a client with a set of scopes.
 * codes    = short-lived authorization codes (single use, PKCE).
 * tokens   = access + refresh token pairs for a grant. Only SHA-256 hashes are stored.
 *
 * Table names: PANEL_MCP_TABLE_PREFIX (default ext_panel_mcp_) + oauth_*, as in PanelMcp\Tables. The panel runs
 * extension migrations before the extension's classes are autoloadable, so this file repeats the rule.
 * Every table is only created when it is missing, so pointing the prefix at tables an earlier
 * install made (same layout) changes nothing.
 */
return new class extends Migration
{
    private function table(string $name): string
    {
        $prefix = (string) (config('panel-mcp.table_prefix') ?? env('PANEL_MCP_TABLE_PREFIX', 'ext_panel_mcp_'));

        return (preg_match('/^[a-z][a-z0-9_]{0,30}$/', $prefix) === 1 ? $prefix : 'ext_panel_mcp_').$name;
    }

    public function up(): void
    {
        if (! Schema::hasTable($this->table('oauth_clients'))) {
            Schema::create($this->table('oauth_clients'), function (Blueprint $table) {
                $table->increments('id');
                $table->string('client_id', 64)->unique();
                $table->string('name', 100);
                $table->text('redirect_uris');
                $table->string('created_ip', 45)->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable($this->table('oauth_grants'))) {
            Schema::create($this->table('oauth_grants'), function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('user_id');
                $table->unsignedInteger('client_id');
                $table->string('scopes', 191);
                $table->string('created_ip', 45)->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->string('last_used_ip', 45)->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();

                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->foreign('client_id')->references('id')->on($this->table('oauth_clients'))->cascadeOnDelete();
                $table->index(['user_id', 'revoked_at']);
            });
        }

        if (! Schema::hasTable($this->table('oauth_codes'))) {
            Schema::create($this->table('oauth_codes'), function (Blueprint $table) {
                $table->increments('id');
                $table->char('code_hash', 64)->unique();
                $table->unsignedInteger('grant_id');
                $table->text('redirect_uri');
                $table->string('code_challenge', 128);
                $table->timestamp('expires_at');
                $table->timestamp('used_at')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->foreign('grant_id')->references('id')->on($this->table('oauth_grants'))->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable($this->table('oauth_tokens'))) {
            Schema::create($this->table('oauth_tokens'), function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('grant_id');
                $table->char('access_hash', 64)->unique();
                $table->char('refresh_hash', 64)->unique();
                $table->timestamp('access_expires_at');
                $table->timestamp('refresh_expires_at');
                // Set when the refresh token was exchanged for a new pair. Using it again later is
                // treated as theft and revokes the whole grant.
                $table->timestamp('rotated_at')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->foreign('grant_id')->references('id')->on($this->table('oauth_grants'))->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('oauth_tokens'));
        Schema::dropIfExists($this->table('oauth_codes'));
        Schema::dropIfExists($this->table('oauth_grants'));
        Schema::dropIfExists($this->table('oauth_clients'));
    }
};
