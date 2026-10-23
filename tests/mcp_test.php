<?php

use App\Core\Container;
use App\Core\Response;
use App\Core\Settings;
use App\Modules\Appearance\DesignLibrary;
use App\Modules\Design\Characters;
use App\Modules\Mcp\McpKeys;
use App\Modules\Mcp\McpTools;
use App\Modules\Pages\Page;
use App\Modules\Pages\PageDraft;

// An AI client over MCP (PLAN.md O-78), through the router as a client reaches it: POST /mcp with
// a key. adminSite() and adminPost() come from pages_admin_test.php, mediaAdminContainer() from
// media_admin_test.php.

const MCP_APP_KEY = 'test-key-not-a-secret';

/** A site with a key: one that writes unless told otherwise. */
function mcpKey(App\Core\Db $db, bool $write = true): string
{
    return McpKeys::create($db, 'Test client', $write, MCP_APP_KEY)['key'];
}

/**
 * One message to /mcp, as a client sends it.
 *
 * @param array<string, mixed> $message
 * @param array<string, string> $headers header name => value
 */
function mcp(array $message, string $key, array $headers = [], ?Closure $configure = null, string $method = 'POST'): Response
{
    $set = ['HTTP_AUTHORIZATION' => 'Bearer ' . $key];
    foreach ($headers as $name => $value) {
        $set['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
    }
    $before = $_SERVER;
    $_SERVER = $set + $_SERVER;
    try {
        return dispatch('/mcp', null, $method, $message, '203.0.113.10', $configure);
    } finally {
        $_SERVER = $before;
    }
}

/**
 * A modern (2026-07-28) request and the headers that mirror it.
 *
 * @param array<string, mixed> $params
 * @return array{array<string, mixed>, array<string, string>}
 */
function modern(string $method, array $params = [], int $id = 1): array
{
    $params['_meta'] = ['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientInfo' => ['name' => 'test', 'version' => '1'], 'io.modelcontextprotocol/clientCapabilities' => []];
    $headers = ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => $method];
    if (isset($params['name'])) {
        $headers['Mcp-Name'] = (string) $params['name'];
    }

    return [['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params], $headers];
}

/**
 * A tool called the modern way, its structured result.
 *
 * @param array<string, mixed> $arguments
 * @return array<string, mixed> the JSON-RPC result
 */
function tool(string $key, string $name, array $arguments = [], ?Closure $configure = null): array
{
    [$message, $headers] = modern('tools/call', ['name' => $name, 'arguments' => $arguments]);
    $response = mcp($message, $key, $headers, $configure);
    $answer = json_decode($response->body, true);

    return is_array($answer['result'] ?? null) ? $answer['result'] : fail("{$name}: " . $response->status . ' ' . $response->body);
}

testBothDrivers('only a key, from no other site, opens the endpoint', function (string $driver) {
    $db = adminSite($driver);
    unset($_SESSION['admin_id']);
    $key = mcpKey($db);
    [$message, $headers] = modern('server/discover');

    $none = mcp($message, '', $headers);
    assertEquals(401, $none->status, 'no key');
    assertEquals('Bearer realm="Boxlet"', $none->headers['WWW-Authenticate'] ?? null, 'saying how to give one');
    assertEquals(401, mcp($message, 'bxk_' . str_repeat('0', 48), $headers)->status, 'a key that is none');
    assertEquals(403, mcp($message, $key, $headers + ['Origin' => 'https://elsewhere.example'])->status, 'another site\'s page');
    assertEquals(200, mcp($message, $key, $headers + ['Origin' => 'http://example.test'])->status, 'its own origin');
    assertEquals(405, mcp([], $key, [], null, 'GET')->status, 'no stream to open');
    $discover = json_decode(mcp($message, $key, $headers)->body, true);
    assertEquals(['2026-07-28', '2025-11-25', '2025-06-18', '2025-03-26'], $discover['result']['supportedVersions'] ?? null, 'the versions it speaks');
    assertEquals('complete', $discover['result']['resultType'] ?? null, 'a modern result');
    // The caching hints a modern client requires (Claude Code refused a list without them).
    assertEquals([3600000, 'public'], [$discover['result']['ttlMs'] ?? null, $discover['result']['cacheScope'] ?? null], 'discover, the same for every caller');
    [$list, $listHeaders] = modern('tools/list');
    $tools = json_decode(mcp($list, $key, $listHeaders)->body, true)['result'] ?? [];
    assertEquals(['complete', 60000, 'private'], [$tools['resultType'] ?? null, $tools['ttlMs'] ?? null, $tools['cacheScope'] ?? null], 'the tools, which follow the key');
    assertTrue(($tools['tools'] ?? []) !== [], 'and the tools are there');

    McpKeys::revoke($db, (int) ($db->one('SELECT id FROM mcp_keys')['id'] ?? 0));
    assertEquals(401, mcp($message, $key, $headers)->status, 'a revoked key');
    assertTrue(!str_contains((string) ($db->one('SELECT key_hash FROM mcp_keys')['key_hash'] ?? ''), $key), 'the key itself is stored');
});

testBothDrivers('a modern request whose headers say otherwise than its body is refused', function (string $driver) {
    $db = adminSite($driver);
    $key = mcpKey($db);
    [$message, $headers] = modern('tools/call', ['name' => 'get_site', 'arguments' => []]);

    $mismatch = static fn (array $h): array => json_decode(mcp($message, $key, $h)->body, true)['error'] ?? [];
    assertEquals(-32020, $mismatch(['Mcp-Method' => 'tools/list'] + $headers)['code'] ?? null, 'another method in the header');
    assertEquals(-32020, $mismatch(['Mcp-Name' => 'list_pages'] + $headers)['code'] ?? null, 'another tool');
    assertEquals(-32020, $mismatch(array_diff_key($headers, ['MCP-Protocol-Version' => 1]))['code'] ?? null, 'no version header');
    assertEquals(200, mcp($message, $key, ['Mcp-Name' => '=?base64?' . base64_encode('get_site') . '?='] + $headers)->status, 'a name in Base64 is read');

    $old = $message;
    $old['params']['_meta']['io.modelcontextprotocol/protocolVersion'] = '1900-01-01';
    $unsupported = mcp($old, $key, ['MCP-Protocol-Version' => '1900-01-01'] + $headers);
    $error = json_decode($unsupported->body, true)['error'] ?? [];
    assertEquals([400, -32022], [$unsupported->status, $error['code'] ?? null], 'a version it does not speak');
    assertTrue(in_array('2026-07-28', $error['data']['supported'] ?? [], true), 'with the ones it does');

    [$unknown, $unknownHeaders] = modern('prompts/list');
    assertEquals(404, mcp($unknown, $key, $unknownHeaders)->status, 'a method it has not');
});

testBothDrivers('a legacy client opens with initialize, and is served without a session', function (string $driver) {
    $db = adminSite($driver);
    $read = mcpKey($db, false);
    $write = mcpKey($db, true);

    $init = mcp(['jsonrpc' => '2.0', 'id' => 0, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'old', 'version' => '1']]], $read);
    $answer = json_decode($init->body, true)['result'] ?? [];
    assertEquals(['2025-06-18', 'boxlet'], [$answer['protocolVersion'] ?? null, $answer['serverInfo']['name'] ?? null], 'its version, echoed');
    assertTrue(!isset($init->headers['Mcp-Session-Id']), 'no session');
    $note = mcp(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $read, ['MCP-Protocol-Version' => '2025-06-18']);
    assertEquals([202, ''], [$note->status, $note->body], 'a notification taken, with nothing to say');

    $names = static fn (string $key): array => array_column(json_decode(mcp(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], $key, ['MCP-Protocol-Version' => '2025-06-18'])->body, true)['result']['tools'] ?? [], 'name');
    assertTrue(in_array('get_page', $names($read), true) && !in_array('update_page', $names($read), true), 'a key that reads is offered reading');
    assertTrue(in_array('update_page', $names($write), true) && !in_array('publish_page', $names($write), true), 'one that writes, writing, not publishing');
    Settings::set($db, McpTools::PUBLISH_SETTING, true);
    assertTrue(in_array('publish_page', $names($write), true), 'publishing, once the owner allows it');
    assertTrue(!in_array('publish_page', $names($read), true), 'and never to a key that reads');
});

testBothDrivers('a page is made, read, changed as a draft and published only where allowed', function (string $driver) {
    $db = adminSite($driver);
    $key = mcpKey($db);

    $made = tool($key, 'create_page', ['locale' => 'en', 'title' => 'Opening hours', 'sections' => [['key' => 'm1', 'layout' => 'one']], 'blocks' => [
        ['key' => 'n1', 'type' => 'text', 'section' => 'm1', 'content' => ['heading' => 'When we are open', 'body' => '<p>Every day.</p><script>alert(1)</script>']],
        ['key' => 'n2', 'type' => 'no-such-block', 'section' => 'm1', 'content' => []],
        ['key' => 'n3', 'type' => 'text', 'section' => 'm1', 'content' => ['heading' => 'Empty']],
    ]]);
    $data = $made['structuredContent'] ?? [];
    $id = (int) ($data['id'] ?? 0);
    $page = Page::find($db, $id) ?? fail('no page made');
    assertEquals(['opening-hours', 'draft'], [(string) $page['slug'], (string) $page['status']], 'its address from its title, unpublished');
    assertTrue(in_array('1 block(s) left out: their type is not one list_block_types names.', $data['problems'] ?? [], true), 'a type it has not, said');
    assertTrue((bool) array_filter($data['problems'] ?? [], static fn (string $p): bool => str_starts_with($p, 'n3 (text) body')), 'an empty required field, said');

    $listed = tool($key, 'list_pages', ['locale' => 'en'])['structuredContent']['pages'] ?? [];
    assertTrue(in_array(['id' => $id, 'title' => 'Opening hours', 'published' => false, 'has_draft' => true], array_map(static fn (array $p): array => array_intersect_key($p, array_flip(['id', 'title', 'published', 'has_draft'])), $listed), true), 'listed, with its draft');
    assertTrue((tool($key, 'list_pages', ['locale' => 'xx'])['isError'] ?? false) === true, 'a language the site has not, refused');
    $read = tool($key, 'get_page', ['id' => $id])['structuredContent'] ?? [];
    assertEquals([1, true], [$read['version'] ?? null, $read['drafted'] ?? null], 'the draft, at version 1');
    $body = (string) ($read['document']['blocks'][0]['content']['body'] ?? '');
    assertTrue(str_contains($body, 'Every day.') && !str_contains($body, 'script'), 'cleaned as the editor cleans it');

    $document = $read['document'];
    $document['blocks'][0]['content']['body'] = '<p>Every day but Monday.</p>';
    $changed = tool($key, 'update_page', ['id' => $id, 'version' => 1, 'sections' => $document['sections'], 'blocks' => $document['blocks']]);
    assertEquals(2, $changed['structuredContent']['version'] ?? null, 'saved as version 2');
    $stale = tool($key, 'update_page', ['id' => $id, 'version' => 1, 'sections' => $document['sections'], 'blocks' => $document['blocks']]);
    assertTrue(($stale['isError'] ?? false) === true && str_contains((string) ($stale['content'][0]['text'] ?? ''), 'changed after version 1'), 'not written over a change made meanwhile');
    assertEquals('draft', (string) (Page::find($db, $id)['status'] ?? ''), 'still unpublished');

    [$call, $headers] = modern('tools/call', ['name' => 'publish_page', 'arguments' => ['id' => $id]]);
    assertEquals(-32602, json_decode(mcp($call, $key, $headers)->body, true)['error']['code'] ?? null, 'publishing not offered until the owner allows it');
    Settings::set($db, McpTools::PUBLISH_SETTING, true);
    $published = tool($key, 'publish_page', ['id' => $id]);
    assertEquals(true, $published['structuredContent']['published'] ?? null, 'published');
    assertTrue(!PageDraft::exists($db, $id), 'the draft is the page now');
    assertContains('Every day but Monday.', dispatch('/opening-hours')->body, 'and visitors see it');
    $kinds = array_column($db->all("SELECT action FROM activity WHERE kind = 'page' ORDER BY id"), 'action');
    assertEquals(['ai_created', 'ai_drafted', 'published'], array_values(array_intersect($kinds, ['ai_created', 'ai_drafted', 'published'])), 'each step in the activity log');
});

testBothDrivers('a picture is fetched into the library, described and listed', function (string $driver) {
    $db = adminSite($driver);
    $key = mcpKey($db);
    // Skipped where GD is not, as every test that makes a picture is (imageFixture).
    $jpeg = (string) file_get_contents(imageFixture(tmpPath('mcp-harbour.jpg'), 640, 400));
    $media = mediaAdminContainer();
    $fetching = static function (Container $c) use ($media, $jpeg): void {
        $media($c);
        $c->set('mcp_fetch', static fn () => static fn (string $url): array => ['bytes' => $jpeg, 'type' => 'image/jpeg']);
    };
    $added = tool($key, 'add_media_from_url', ['url' => 'https://pictures.example/harbour.jpg', 'alt' => 'The harbour at dawn'], $fetching)['structuredContent'] ?? [];
    $id = (int) ($added['id'] ?? 0);
    assertEquals('harbour', (string) ($db->one('SELECT filename FROM media WHERE id = ?', [$id])['filename'] ?? ''), 'named after its address');
    assertEquals('The harbour at dawn', $added['picture']['alt']['en'] ?? null, 'described');
    $library = tool($key, 'list_media', [], $fetching)['structuredContent']['media'] ?? [];
    assertEquals($id, $library[0]['id'] ?? null, 'and in the library\'s list, newest first');
    $refused = tool($key, 'add_media_from_url', ['url' => 'https://pictures.example/notes.txt'], static function (Container $c) use ($media): void {
        $media($c);
        $c->set('mcp_fetch', static fn () => static fn (string $url): array => ['bytes' => 'hello', 'type' => 'text/plain']);
    });
    assertTrue(($refused['isError'] ?? false) === true, 'what is no picture is refused');
});

testBothDrivers('a design set is kept, as a character or a saved design, never applied', function (string $driver) {
    $db = adminSite($driver);
    $key = mcpKey($db);

    $active = (string) (Settings::get($db, 'design_character') ?? '');
    $set = json_decode((string) file_get_contents(dirname(__DIR__) . '/designs/core/minimal.json'), true);
    $set['id'] = 'quiet-harbour';
    $set['name'] = ['en' => 'Quiet harbour'];
    $kept = tool($key, 'create_design_set', ['set' => $set])['structuredContent'] ?? [];
    assertEquals(['character', 'quiet-harbour'], [$kept['as'] ?? null, $kept['id'] ?? null], 'a set with a composition kept as a character');
    assertTrue(Characters::exists('quiet-harbour'), 'one more to choose from');
    assertEquals($active, (string) (Settings::get($db, 'design_character') ?? ''), 'and the site\'s own unchanged');

    assertTrue(Characters::byAi('quiet-harbour') && !Characters::byAi('minimal'), 'marked as an AI client\'s');

    unset($set['composition']);
    $set['name'] = ['en' => 'Harbour colours'];
    $saved = tool($key, 'create_design_set', ['set' => $set])['structuredContent'] ?? [];
    assertEquals('saved design', $saved['as'] ?? null, 'one without, a saved design');
    // D-290: the owner looked for it among the characters; the link lands on Your designs.
    assertEquals('http://example.test/admin/appearance#group-designs', $saved['try_it'] ?? null, 'the link lands where it is');
    $listed = tool($key, 'get_design')['structuredContent']['saved_designs'] ?? [];
    assertEquals([['id' => $saved['id'] ?? null, 'name' => 'Harbour colours', 'from_character' => 'minimal', 'by_ai' => true]], $listed, 'get_design lists it, as an AI client\'s');
    assertEquals($saved['id'] ?? null, tool($key, 'create_design_set', ['set' => $set])['structuredContent']['id'] ?? null, 'the same name again writes over it');
    assertEquals(1, count(DesignLibrary::all($db)), 'rather than adding a second');

    $screen = dispatch('/admin/appearance')->body;
    assertEquals(1, preg_match('~id="group-designs".*?Harbour colours</span>\s*<span class="design-row-from"><span class="design-row-ai"~s', $screen), 'Appearance marks it under Your designs');
    assertEquals(1, preg_match('~Quiet harbour</span>\s*<span class="tile-tags"><span class="tile-custom" title="[^"]+">AI</span>~', $screen), 'and the character\'s tile');
    DesignLibrary::save($db, 'Harbour colours', [], []);
    assertTrue(!DesignLibrary::all($db)[0]['by_ai'], 'the owner\'s save over it makes it theirs');
    assertTrue(!str_contains(dispatch('/admin/appearance')->body, 'class="design-row-ai"'), 'and the mark goes');
    $bad = $set;
    $bad['decisions'] = ['seed' => 'not a colour'];
    assertTrue((tool($key, 'create_design_set', ['set' => $bad])['isError'] ?? false) === true, 'one that does not read, refused with why');
    $schema = tool($key, 'get_design_set_schema')['structuredContent'] ?? [];
    assertTrue(isset($schema['properties']['decisions']), 'the format, for a model to write one');
});

testBothDrivers('a key has its minute\'s worth of calls', function (string $driver) {
    $db = adminSite($driver);
    $key = mcpKey($db);
    $id = (int) ($db->one('SELECT id FROM mcp_keys')['id'] ?? 0);
    for ($n = 0; $n < McpKeys::PER_MINUTE; $n++) {
        $db->query('INSERT INTO mcp_calls (key_id, created_at) VALUES (?, ?)', [$id, gmdate('Y-m-d H:i:s')]);
    }
    [$message, $headers] = modern('server/discover');
    $limited = mcp($message, $key, $headers);
    assertEquals(429, $limited->status, 'refused');
    assertEquals('60', $limited->headers['Retry-After'] ?? null, 'and told when to come back');
});

testBothDrivers('the owner makes a key, sees it once, and revokes it', function (string $driver) {
    $db = adminSite($driver);
    assertRedirectedTo('/admin/settings#mcp', adminPost('/admin/settings/mcp/keys', ['name' => 'Claude', 'write' => '1']));
    $first = dispatch('/admin/settings')->body;
    preg_match('~id="mcp-new-key" value="(bxk_[0-9a-f]{48})"~', $first, $shown);
    $key = $shown[1] ?? fail('the key was not shown');
    assertContains('claude mcp add --transport http boxlet http://example.test/mcp --header "Authorization: Bearer ' . $key, $first, 'with the line that connects Claude Code');
    assertTrue(!str_contains(dispatch('/admin/settings')->body, $key), 'and never again');
    [$message, $headers] = modern('server/discover');
    assertEquals(200, mcp($message, $key, $headers)->status, 'it opens the endpoint');

    $id = (int) ($db->one('SELECT id FROM mcp_keys')['id'] ?? 0);
    assertRedirectedTo('/admin/settings#mcp', adminPost('/admin/settings/mcp/keys/' . $id . '/revoke', []));
    assertEquals(401, mcp($message, $key, $headers)->status, 'revoked, it opens nothing');
    assertContains('/admin/settings/mcp/keys/' . $id . '/remove', dispatch('/admin/settings')->body, 'a revoked key may be taken off the list');
    assertRedirectedTo('/admin/settings#mcp', adminPost('/admin/settings/mcp/keys/' . $id . '/remove', []));
    assertTrue($db->one('SELECT id FROM mcp_keys WHERE id = ?', [$id]) === null, 'and is');
    assertRedirectedTo('/admin/settings#mcp', adminPost('/admin/settings/mcp', ['publish' => '1']));
    assertEquals(true, Settings::get($db, McpTools::PUBLISH_SETTING), 'publishing allowed');
});

testBothDrivers('the site, its block types and its design are read as a model needs them', function (string $driver) {
    $db = adminSite($driver);
    $key = mcpKey($db, false);

    $site = tool($key, 'get_site')['structuredContent'] ?? [];
    assertEquals(['en', true], [$site['languages'][0]['code'] ?? null, $site['languages'][0]['main'] ?? null], 'the main language first');
    assertEquals(['writes' => false, 'publishes' => false], $site['this_key'] ?? null, 'what this key may do');

    $types = tool($key, 'list_block_types')['structuredContent'] ?? [];
    $body = $types['blocks']['text']['fields']['body'] ?? [];
    assertEquals(['richtext', true], [$body['type'] ?? null, $body['required'] ?? null], 'a field, its type and whether it is required');
    assertTrue(in_array('link', $body['allow'] ?? [], true), 'and, for rich text, what it allows');
    assertTrue(in_array('columns', $types['blocks']['text']['layouts'] ?? [], true), 'a block\'s layouts');
    assertTrue(isset($types['sections']['layouts']['halves'], $types['sections']['style']['surface']), 'and what a section may be');

    $design = tool($key, 'get_design')['structuredContent'] ?? [];
    assertEquals(['boxlet-design-set', 2], [$design['set']['format'] ?? null, $design['set']['version'] ?? null], 'the site\'s design as a set file');
    assertTrue(in_array('minimal', array_column($design['characters'] ?? [], 'id'), true), 'and the characters to start from');
});
