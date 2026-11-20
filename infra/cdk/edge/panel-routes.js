// The routes of the web app, a CloudFront Function (runtime cloudfront-js-2.0) on the viewer request of the default
// behaviour of the distribution only (infra/cdk/lib/edge-stack.ts): a path whose last segment has no extension is a
// route of the web app, which index.html answers and its router shows; a file keeps its path, and one that does not
// exist is a 404 of the bucket. The paths of the API never reach it: they have behaviours of their own, so their
// errors stay those of the API. The proxy of the emulated cloud, where CloudFront Functions do not run, does the same
// (infra/functions/gateway.conf.template); both pass the cases of panel-routes.cases.json.
/* exported handler */
function handler(event) {
  var request = event.request
  var last = request.uri.substring(request.uri.lastIndexOf('/') + 1)

  if (last.indexOf('.') === -1) {
    request.uri = '/index.html'
  }

  return request
}
