/**
 * shot-cdp.mjs — screenshot yazan.local with REAL device emulation.
 *
 * WHY THIS EXISTS
 * ---------------
 * `chrome --headless --screenshot --window-size=390,844` does NOT emulate a phone. It renders in
 * desktop mode at a 390px-wide window, and desktop Chrome ignores `<meta name="viewport">`. The
 * result looks plausible but lies: pages appear to overflow and get clipped at the right edge when
 * a real phone renders them perfectly. That artefact cost a debugging session — a "mobile overflow
 * bug" was investigated and partly chased before CDP showed `scrollWidth === clientWidth === 390`,
 * i.e. no overflow had ever existed.
 *
 * Only the DevTools protocol can set the mobile flag (`Emulation.setDeviceMetricsOverride` with
 * `mobile: true`), so mobile shots go through here. Desktop shots can stay on the plain CLI path
 * in shot.sh, because there the two agree.
 *
 * Usage (normally called by shot.sh, not directly):
 *   node shot-cdp.mjs <url> <out.png> <width> <height> <mobile:0|1> [full:0|1]
 *
 * Requires Node 18+ for the global `fetch` and `WebSocket` (this machine runs Node 24).
 */

import { spawn } from 'node:child_process';
import { writeFileSync } from 'node:fs';

const [url, out, w = '390', h = '844', mobileArg = '1', fullArg = '0'] = process.argv.slice(2);

if (!url || !out) {
	console.error('usage: node shot-cdp.mjs <url> <out.png> [width] [height] [mobile] [full]');
	process.exit(1);
}

const width = Number(w);
const height = Number(h);
const mobile = mobileArg === '1';
const full = fullArg === '1';

const CHROME_CANDIDATES = [
	'C:/Program Files/Google/Chrome/Application/chrome.exe',
	'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
	'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
];

const IPHONE_UA =
	'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 ' +
	'(KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

// A high port, so a stray Chrome from another tool does not collide with ours.
const PORT = 9500 + Math.floor(process.pid % 400);

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const chrome = spawn(
	CHROME_CANDIDATES.find(Boolean),
	[
		'--headless=new',
		'--disable-gpu',
		'--hide-scrollbars',
		`--remote-debugging-port=${PORT}`,
		// Same two flags the CLI path uses: resolve the local domain and accept its cert.
		'--host-resolver-rules=MAP yazan.local 127.0.0.1',
		'--ignore-certificate-errors',
		'about:blank',
	],
	{ stdio: 'ignore' }
);

/** Poll the debugging endpoint instead of guessing a fixed startup delay. */
async function attach() {
	for (let i = 0; i < 40; i++) {
		try {
			const list = await (await fetch(`http://127.0.0.1:${PORT}/json`)).json();
			const page = list.find((t) => t.type === 'page');
			if (page) return page;
		} catch {
			/* Chrome is not listening yet. */
		}
		await sleep(250);
	}
	throw new Error('Chrome did not expose a debugging target');
}

let socket;
let nextId = 0;
const pending = new Map();

const send = (method, params = {}) =>
	new Promise((resolve) => {
		const id = ++nextId;
		pending.set(id, resolve);
		socket.send(JSON.stringify({ id, method, params }));
	});

try {
	const target = await attach();

	socket = new WebSocket(target.webSocketDebuggerUrl);
	socket.onmessage = (event) => {
		const msg = JSON.parse(event.data);
		if (msg.id && pending.has(msg.id)) {
			pending.get(msg.id)(msg.result);
			pending.delete(msg.id);
		}
	};
	await new Promise((resolve) => {
		socket.onopen = resolve;
	});

	// THE point of this script: `mobile: true` is what makes Chrome honour the viewport meta tag.
	await send('Emulation.setDeviceMetricsOverride', {
		width,
		height,
		deviceScaleFactor: mobile ? 2 : 1,
		mobile,
	});

	if (mobile) {
		// Some plugins branch on the UA rather than on width; without this their mobile paths
		// never run and the shot is still not what a phone sees.
		await send('Emulation.setUserAgentOverride', { userAgent: IPHONE_UA });
		await send('Emulation.setTouchEmulationEnabled', { enabled: true, maxTouchPoints: 5 });
	}

	await send('Page.enable');
	await send('Page.navigate', { url });

	// Fonts are self-hosted and there are no external requests, so a fixed settle beats waiting on
	// a network-idle event that a polling widget (order alerts, chat) can keep from ever firing.
	await sleep(5000);

	const shot = await send('Page.captureScreenshot', {
		format: 'png',
		captureBeyondViewport: full,
	});

	writeFileSync(out, Buffer.from(shot.data, 'base64'));

	// Report the real overflow numbers — the thing the CLI path silently gets wrong.
	const metrics = await send('Runtime.evaluate', {
		returnByValue: true,
		expression:
			'({ client: document.documentElement.clientWidth, scroll: document.documentElement.scrollWidth })',
	});
	const { client, scroll } = metrics.result.value;

	console.log(out);
	console.log(
		`viewport ${client}px · scrollWidth ${scroll}px · ${
			scroll > client ? `OVERFLOW +${scroll - client}px` : 'no horizontal overflow'
		}`
	);
} finally {
	socket?.close();
	chrome.kill();
}
