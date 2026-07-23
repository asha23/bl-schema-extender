<?php
/**
 * BrightLocal MCP — admin screen.
 *
 * A single status/reference page under the BL AI Tools menu: whether the MCP
 * server is live, its endpoint, the exposed tools, and how to connect
 * (Application Password auth). Signposts the MCP Adapter dependency when absent.
 *
 * @since 2.25.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BL_MCP_Admin {

	public function render_page() {
		$available = BL_MCP_Server::available();
		$endpoint  = BL_MCP_Server::endpoint_url();
		$cap       = BL_MCP_Abilities::capability();
		?>
		<div class="wrap">
			<h1>BrightLocal MCP</h1>
			<p class="description" style="font-size:14px;max-width:720px;">A dedicated <a href="https://modelcontextprotocol.io" target="_blank" rel="noopener">Model Context Protocol</a> server that lets AI assistants search and read BrightLocal&rsquo;s published content directly. Built on the WordPress Abilities API + MCP Adapter.</p>

			<?php if ( ! $available ) : ?>
				<div class="notice notice-warning inline" style="margin:1em 0;max-width:820px;">
					<p><strong>The MCP server is not active.</strong> It requires the <strong>MCP Adapter</strong> plugin to be active and <strong>WordPress 6.9+</strong> (for the Abilities API). Everything else in BL AI Tools works without it.</p>
				</div>
			<?php else : ?>
				<div class="notice notice-success inline" style="margin:1em 0;max-width:820px;">
					<p><span class="dashicons dashicons-yes" style="color:#008a20;"></span> <strong>MCP server is registered.</strong></p>
				</div>
			<?php endif; ?>

			<div class="card" style="max-width:820px;padding:4px 24px 18px;">
				<h2>Endpoint</h2>
				<p>Connect your MCP client to:</p>
				<p><input type="text" class="widefat code" readonly value="<?php echo esc_attr( $endpoint ); ?>" onclick="this.select()"></p>
				<p class="description">HTTP transport (MCP spec). Use HTTPS in production.</p>
			</div>

			<div class="card" style="max-width:820px;padding:4px 24px 18px;">
				<h2>Tools exposed</h2>
				<table class="widefat striped">
					<tbody>
						<tr><td style="width:200px;"><code>search_content</code></td><td>Search published content (guides, posts, pages) &mdash; returns titles, URLs, and excerpts.</td></tr>
						<tr><td><code>get_content</code></td><td>Fetch one published page/post in full (readable text), by URL or ID.</td></tr>
						<?php if ( class_exists( 'BL_EntityMap_Store' ) ) : ?>
						<tr><td><code>search_entities</code></td><td>Search/list the curated EntityMap (products, services, concepts, research) &mdash; name, type, description, sameAs, page.</td></tr>
						<tr><td><code>get_entity</code></td><td>Fetch one entity in full (evidence, relationships, sameAs), by id or name.</td></tr>
						<?php endif; ?>
					</tbody>
				</table>
				<p class="description" style="margin-bottom:0;">Read-only, and limited to <strong>published, public</strong> content &mdash; never drafts or private posts.<?php echo class_exists( 'BL_EntityMap_Store' ) ? ' The entity tools read the same curated EntityMap you manage under Entity Maps.' : ''; ?></p>
			</div>

			<div class="card" style="max-width:820px;padding:4px 24px 18px;">
				<h2>Authentication</h2>
				<p>The endpoint requires an authenticated user (capability: <code><?php echo esc_html( $cap ); ?></code>). The simplest method is a WordPress <strong>Application Password</strong>:</p>
				<ol>
					<li>Go to <strong>Users &rarr; Profile</strong> and, under <em>Application Passwords</em>, create one (e.g. named &ldquo;BrightLocal MCP&rdquo;).</li>
					<li>In your MCP client, add this endpoint and authenticate with that username + application password (HTTP Basic, over HTTPS).</li>
				</ol>
				<p class="description" style="margin-bottom:0;">Prefer a dedicated, low-privilege user for MCP access. The required capability is filterable via <code>bl_mcp_capability</code>.</p>
			</div>
		</div>
		<?php
	}
}
