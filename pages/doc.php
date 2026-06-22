<?php
/*
 * This file is part of EPUB TTS, created by Patrick Clark.
 *
 * EPUB TTS is free software: you can redistribute it and/or modify it under the
 * terms of the GNU General Public License, version 3 or (at your option) any
 * later version, as published by the Free Software Foundation. It comes with NO
 * WARRANTY. See the LICENSE file or <https://www.gnu.org/licenses/>.
 *
 * Copyright (C) 2016-2026 Patrick Clark and family.
 *
 * Patrick built EPUB TTS over many years. The GPL licensing was applied by his
 * family when the project was made public, to keep his work free for everyone --
 * honoring his wishes. It was not part of the original source.
 */
/**
 * Simple Documentation Page Template
 * ----------------------------------
 * Drop-in PHP file for internal or public docs.
 * PHP is only used here for metadata and optional helpers.
 */
date_default_timezone_set("America/Chicago");
$lastUpdated =   date("F j, Y, g:i a", filemtime(__FILE__));
$title = "EPUB TTS­­";
$version = "2.0";

/**
 * Optional helper for anchor-safe IDs
 */
function slug($text) {
    return strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $text), '-'));
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<!--<title><?= htmlspecialchars($title) ?></title>-->
<meta name="viewport" content="width=device-width, initial-scale=1">

<style>
/* -------------------------
   Base Typography & Layout
   ------------------------- */

:root {
    --bg: #ffffff;
    --fg: #1f2937;
    --muted: #6b7280;
    --border: #e5e7eb;
    --accent: #2563eb;
    --code-bg: #f8fafc;
    --note-bg: #f0f9ff;
    --warn-bg: #fff7ed;
}

@media (prefers-color-scheme: dark) {
    :root {
        --bg: #0f172a;
        --fg: #e5e7eb;
        --muted: #94a3b8;
        --border: #1e293b;
        --accent: #60a5fa;
        --code-bg: #020617;
        --note-bg: #020617;
        --warn-bg: #1f1406;
    }
}

html, body {
    background: var(--bg);
    color: var(--fg);
    margin: 0;
    font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
    line-height: 1.6;
hyphens: auto; /* Enables automatic hyphenation */
}

/* -------------------------
   Page Structure
   ------------------------- */

.wrapper {
    display: grid;
    grid-template-columns: 40% 60%;
    max-width: 100%;
    margin: 0 auto;
}

aside {
    border-right: 1px solid var(--border);
    padding: 2rem 1.5rem;
    position: sticky;
    top: 0;
    height: 100vh;
}

main {
    padding: 3rem;
}

/* -------------------------
   Sidebar
   ------------------------- */

aside h2 {
    font-size: 0.9rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--muted);
    margin-top: 0;
}

aside ul {
    list-style: none;
    padding: 0;
}

aside li {
    margin: 0.5rem 0;
}

aside a {
    color: var(--fg);
    text-decoration: none;
    font-size: 0.95rem;
}

aside a:hover {
    color: var(--accent);
}

/* -------------------------
   Content Styling
   ------------------------- */

h1 {
    font-size: 2.2rem;
    margin-top: 0;
}

h2 {
    margin-top: 3rem;
    padding-top: 1rem;
    border-top: 1px solid var(--border);
}

h3 {
    margin-top: 2rem;
}

p {
	font-size: 0.95em;
        font-family: serif;
}

a {
	color:graytext;
}
code, pre {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}

pre {
    background: var(--code-bg);
    padding: 1rem;
    border-radius: 6px;
    overflow-x: auto;
    border: 1px solid var(--border);
}

.note, .warning {
    padding: 1rem;
    border-left: 4px solid;
    margin: 1.5rem 0;
    border-radius: 4px;
}

.note {
    background: var(--note-bg);
    border-color: #38bdf8;
}

.warning {
    background: var(--warn-bg);
    border-color: #fb923c;
}

.meta {
    color: var(--muted);
    font-size: 0.9rem;
}

/* -------------------------
   Print
   ------------------------- */

@media print {
    aside {
        display: none;
    }
    .wrapper {
        grid-template-columns: 1fr;
    }
}
nav#toc ul {
    list-style: none;
    padding-left: 0;
}

nav#toc li {
    margin: 0.35rem 0;
}

nav#toc ul ul {
    padding-left: 1rem;
    border-left: 1px solid var(--border);
    margin-left: 0.25rem;
}

nav#toc a {
    text-decoration: none;
    color: var(--fg);
    font-size: 0.95rem;
}

nav#toc a:hover {
    color: var(--accent);
}

</style>
</head>

<body>
<div class="wrapper">

    <!-- Sidebar / TOC -->
 <aside>
    <h2>Table of Contents</h2>
    <nav id="toc"></nav>
</aside>

    <!-- Main Content -->
    <main>
        <h1><?= htmlspecialchars($title) ?></h1>
        <p class="meta">
            Version <?= htmlspecialchars($version) ?> · Last updated <?= htmlspecialchars($lastUpdated) ?>
        </p>
 <!--<h1>this would be top line of menu</h1>-->
 <h2>Introduction</h2>
 <p>You have already reached the website, so you can skip the Installation, etc., but here's what you might want to refer to as versions are developed.</p>
<h2>Usage</h3>
<p><i>Open the installed TailScale app</i> and make sure it says connected, or choose connect.</p>
<p><i>Open a browser</i>, and enter something like this:<br><pre>http://123.456.789.012:8888/tts</pre><p>where the 123.456.789.012 is the IP address provided in Tailscale's window. Note that the window also contains a copy function, so you don't have to remember it or type it.</p>
<p>When you reach the front page of the  site, you are greeted by a list of ways you can deal with the books I have access to.</p>
<img src="doc.page1.png" width="100%" height="auto"><p>Don't use the ones in red -- these are for me.</p>
<p><i>Establish a "bookmark" or "favorite"</i> (whatever your browser calls it) for that IP address. Then you'll never have to enter it again.
<p><i>Make sure TailScale is still running.</i> You don't have to close it when done. If it's not, just start it again, and make sure it's connected.</p>
<h3>Basic</h3>
<h2>Installation</h2>
<p>
There really is no installation, unless you're using a network outside our house, like a phone's cell network, or your own Wi-Fi.<br><br>
The only thing you need is a phone, tablet, or computer with a modern internet browser, like FireFox (the one I like best), Chrome (Google's Ad-ware), Edge (Microsoft's Ad-ware), Safari (Apple's) and some knowledge how to use it.
<h2>Requirements</h2>
<p>When you ARE on a cell network or a Wi-Fi that's not ours, I need to invite you, via text or email.<br><br>
You then need to establish an account for each of your devices that you wish to use at  <a href="https://tailscale.com/">TailScale.com.</a>
My invitation tells you how to do it. It's free for personal use, and safe.<br><br>
Tailscale simplifies the connection to <i><b>my computer</b></i> (only) by creating a <b>private</b> network for each account.</p><p>Only you controls who (if anyone) can access your device.</p><p>It establishes <b>encrypted</b> connections with your devices, allowing them to communicate with <b>my computer only</b> <i>after being invited.</i> It does not allow a connection anywhere else unless they invite you, and nobody can access your device unless you invite them.
</p>
<p>Tailscale is available for free for personal use, supporting up to 100 devices in a personal account.</h3>

<h3>Key Features</h3>
	<h4>Setup</h4>
   <p><b>Zero Configuration:</b> Tailscale requires minimal setup. Users simply install the client on their devices and log in with a browser.</p>
    <p><b>Cross-Platform Support:</b> It works on various operating systems, including Windows, macOS, Linux, iOS, and Android.</p>

<h4>Connectivity</h4>
    <p><b>Peer-to-Peer Connections:</b> Devices connect directly, no middle man involved, enhancing speed and privacy.</p>
    <p><b>MagicDNS:</b> Automatically assigns names to devices, making it easier to connect without remembering IP addresses.</p>

<h4>Access</h4>
    <p><b>Identity-Based Access:</b> Users can manage who accesses what through a robust access control system.</p>
    <p><b>Team and External Invites:</b> Easily invite potential users to join your network. Not mine, that's for me to do..</p>
<p><b>Home server networking:</b> Ideal for connecting my server without exposing it or the connecting devices to the internet.</p>

<h3>Steps</h3></h3>
<p>Contact me, requesting to "join" my network.</p>
<p>Install <b>TailScale</b> from your app store. It's safeguarded by the store.</p>
<p>Follow the directions in my email or text invitation.</p>
<p>When you have finished, you will get a a list of devices you can connect to.</p>

    </main>
</div>
<script>
(function () {
    const toc = document.getElementById('toc');
    const headings = document.querySelectorAll(
        'main h1, main h2, main h3, main h4, main h5, main h6'
    );

    if (!headings.length) return;

    const rootUl = document.createElement('ul');

    // Stack entries: { level: number, ul: HTMLUListElement }
    const stack = [{ level: 0, ul: rootUl }];

    headings.forEach(h => {
        const level = parseInt(h.tagName.substring(1), 10);

        // Ensure the heading has an ID
        if (!h.id) {
            h.id = h.textContent
                .toLowerCase()
                .trim()
                .replace(/[^\w]+/g, '-')
                .replace(/(^-|-$)/g, '');
        }

        // Pop until we find a parent level
        while (stack.length > 1 && stack[stack.length - 1].level >= level) {
            stack.pop();
        }

        const parentUl = stack[stack.length - 1].ul;

        const li = document.createElement('li');
        const a = document.createElement('a');
        a.href = `#${h.id}`;
        a.textContent = h.textContent;

        li.appendChild(a);
        parentUl.appendChild(li);

        // Prepare a UL for potential children
        const childUl = document.createElement('ul');
        li.appendChild(childUl);

        stack.push({ level, ul: childUl });
    });

    // Clean up empty <ul>s
    rootUl.querySelectorAll('ul').forEach(ul => {
        if (!ul.children.length) ul.remove();
    });

    toc.appendChild(rootUl);
})();
</script>

</body>
</html>
