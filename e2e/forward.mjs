// The browser of the test opens the app at http://localhost:8601: the origin the API accepts for
// session sign-in (SANCTUM_STATEFUL_DOMAINS) and a host the dev server serves. Inside the e2e
// container that port is this forwarder, which passes every connection, HTTP and the WebSocket of
// Vite alike, to the web service of the compose network (WEB_URL).
import { connect, createServer } from 'node:net'

const target = new URL(process.env.WEB_URL ?? 'http://web:5173')

createServer((client) => {
  const upstream = connect(Number(target.port || 80), target.hostname)

  client.pipe(upstream).pipe(client)
  client.on('error', () => upstream.destroy())
  upstream.on('error', () => client.destroy())
}).listen(8601, '127.0.0.1')
