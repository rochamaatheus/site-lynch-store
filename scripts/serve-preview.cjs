const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
function createPreviewServer(root, prefix) {
  root = path.resolve(root);
  return http.createServer((req, res) => {
    const pathname = decodeURIComponent(new URL(req.url, 'http://localhost').pathname);
    if (!pathname.startsWith(prefix)) { res.writeHead(404); res.end(); return; }
    const file = path.resolve(root, pathname.slice(prefix.length) || 'index.html');
    if (!file.startsWith(root + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) { res.writeHead(404); res.end('404'); return; }
    const type = {'.html':'text/html; charset=utf-8','.css':'text/css','.js':'text/javascript','.json':'application/json','.webp':'image/webp','.png':'image/png','.svg':'image/svg+xml','.woff2':'font/woff2','.txt':'text/plain'};
    res.writeHead(200, {'Content-Type':type[path.extname(file)] || 'application/octet-stream'});
    fs.createReadStream(file).pipe(res);
  });
}
module.exports = {createPreviewServer};
if (require.main === module) {
  const build = JSON.parse(fs.readFileSync(path.join(__dirname, '../.cache/preview-build.json')));
  const server = createPreviewServer(build.output, new URL(build.base).pathname + '/');
  server.listen(8091, '127.0.0.1', () => console.log('Prévia: http://127.0.0.1:8091' + new URL(build.base).pathname + '/'));
}
