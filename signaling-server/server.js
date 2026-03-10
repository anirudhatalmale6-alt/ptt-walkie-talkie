const { WebSocketServer } = require('ws');
const http = require('http');
const url = require('url');

const PORT = process.env.PORT || 8080;

// Groups: { groupId: Set<ws> }
const groups = new Map();

const server = http.createServer((req, res) => {
    res.writeHead(200, {
        'Content-Type': 'text/plain',
        'Access-Control-Allow-Origin': '*'
    });
    res.end('PTT Relay Server Running\n');
});

const wss = new WebSocketServer({ server });

wss.on('connection', (ws, req) => {
    const params = url.parse(req.url, true).query;
    const groupId = params.group || '1';

    // Add to group
    if (!groups.has(groupId)) {
        groups.set(groupId, new Set());
    }
    const group = groups.get(groupId);
    group.add(ws);

    ws._groupId = groupId;

    console.log(`[+] Client joined group ${groupId} (${group.size} members)`);

    // Send member count to all in group
    broadcastCount(groupId);

    ws.on('message', (data) => {
        try {
            const msg = JSON.parse(data.toString());

            switch (msg.type) {
                case 'audio':
                    // Relay audio to all others in same group
                    const audioMsg = JSON.stringify(msg);
                    for (const peer of group) {
                        if (peer !== ws && peer.readyState === 1) {
                            peer.send(audioMsg);
                        }
                    }
                    break;

                case 'tx_start':
                case 'tx_stop':
                    // Relay TX status to all others
                    const statusMsg = JSON.stringify(msg);
                    for (const peer of group) {
                        if (peer !== ws && peer.readyState === 1) {
                            peer.send(statusMsg);
                        }
                    }
                    break;
            }
        } catch (e) {
            // Ignore malformed messages
        }
    });

    ws.on('close', () => {
        group.delete(ws);
        console.log(`[-] Client left group ${groupId} (${group.size} members)`);
        if (group.size === 0) {
            groups.delete(groupId);
        } else {
            broadcastCount(groupId);
        }
    });

    ws.on('error', (err) => {
        console.error(`[!] Error in group ${groupId}:`, err.message);
        group.delete(ws);
    });
});

function broadcastCount(groupId) {
    const group = groups.get(groupId);
    if (!group) return;
    const msg = JSON.stringify({ type: 'count', count: group.size });
    for (const peer of group) {
        if (peer.readyState === 1) {
            peer.send(msg);
        }
    }
}

server.listen(PORT, '0.0.0.0', () => {
    console.log(`PTT Relay Server listening on port ${PORT}`);
    console.log(`Clients connect to: ws://<your-ip>:${PORT}?group=<number>`);
});
