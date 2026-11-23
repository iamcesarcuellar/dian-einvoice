// Assertions on the stack edge of both stages, and of prod with and without a domain of its own: the bucket of the
// web app private and read only by the distribution; HTTPS only, TLS 1.2 at least with a domain, HTTP/2 and HTTP/3;
// the paths of the API to API Gateway, uncached, with everything the application needs and the secret of the edge;
// the cache of the web app; its routes answered by the CloudFront Function of the default behaviour only, never by
// custom error responses (the function passes the cases the proxy of the emulated cloud passes too); the security
// headers of the web app from their single source of truth; the firewall of prod; and the access logs.
import assert from 'node:assert/strict'
import { createHash } from 'node:crypto'
import { readFileSync } from 'node:fs'
import { join, resolve } from 'node:path'
import { describe, it } from 'node:test'
import { runInNewContext } from 'node:vm'
import { App } from 'aws-cdk-lib'
import { buildApp, cdkContext, withEdgeDomain } from '../lib/application.ts'
import { authenticationRegex, FINGERPRINTED_PATH, ORIGIN_HEADER, PANEL_ROUTES_CODE } from '../lib/edge-stack.ts'
import { loadApiPaths, loadAuthenticationPaths, loadSecurityHeaders, renderContentSecurityPolicy, strictTransportSecurity } from '../lib/security-headers.ts'
import { HTTP_API_PARAMETER, originSecretName, STAGES, type StageConfig } from '../lib/stage.ts'
import { stageOf, template, validationReport, variantOf } from './support.ts'

/** The managed cache and origin request policies of CloudFront for an API Gateway origin. */
const CACHING_DISABLED = '4135ea2d-6df8-44a3-9df3-4b5a84be39ad'
const ALL_VIEWER_EXCEPT_HOST_HEADER = 'b689b0a8-53d0-40ab-baf2-68738e2966ac'

/** The longest Content-Security-Policy a response headers policy of CloudFront takes. */
const CLOUDFRONT_POLICY_LIMIT = 1783

const headers = loadSecurityHeaders()
const apiPaths = loadApiPaths()
const authenticationPaths = loadAuthenticationPaths()

interface Behaviour {
  readonly PathPattern?: string
  readonly TargetOriginId: string
  readonly ViewerProtocolPolicy: string
  readonly AllowedMethods: readonly string[]
  readonly CachePolicyId: unknown
  readonly OriginRequestPolicyId?: unknown
  readonly ResponseHeadersPolicyId?: unknown
  readonly Compress: boolean
  readonly FunctionAssociations?: readonly { readonly EventType: string, readonly FunctionARN: unknown }[]
}

interface DistributionConfig {
  readonly DefaultCacheBehavior: Behaviour
  readonly CacheBehaviors: readonly Behaviour[]
  readonly Origins: readonly { readonly Id: string, readonly DomainName: unknown, readonly OriginAccessControlId?: unknown, readonly CustomOriginConfig?: { readonly OriginProtocolPolicy: string, readonly OriginSSLProtocols: readonly string[] }, readonly OriginCustomHeaders?: readonly { readonly HeaderName: string, readonly HeaderValue: unknown }[] }[]
  readonly ViewerCertificate?: { readonly AcmCertificateArn?: string, readonly MinimumProtocolVersion?: string, readonly SslSupportMethod?: string, readonly CloudFrontDefaultCertificate?: boolean }
  readonly Aliases?: readonly string[]
  readonly CustomErrorResponses?: unknown
  readonly WebACLId?: unknown
  readonly [key: string]: unknown
}

function distributionOf(stack: Parameters<typeof template>[0]): DistributionConfig {
  const distributions = Object.values(template(stack).findResources('AWS::CloudFront::Distribution')) as { Properties: { DistributionConfig: DistributionConfig } }[]
  assert.equal(distributions.length, 1)

  return (distributions[0] as { Properties: { DistributionConfig: DistributionConfig } }).Properties.DistributionConfig
}

/** The CloudFront Function of the routes of the web app, run as CloudFront runs it: the handler of a plain script. */
function panelRoutes(): (event: { request: { uri: string } }) => { uri: string } {
  return runInNewContext(`${readFileSync(PANEL_ROUTES_CODE, 'utf8')}\nhandler`, {}) as (event: { request: { uri: string } }) => { uri: string }
}

/** The inline scripts of index.html of the web app, as the browser hashes them. */
function inlineScriptHashes(): string[] {
  const html = readFileSync(process.env.WEB_APP_INDEX ?? resolve(import.meta.dirname, '../../../frontend/index.html'), 'utf8')

  return [...html.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/g)].map((match) => `'sha256-${createHash('sha256').update(match[1] ?? '', 'utf8').digest('base64')}'`)
}

function directives(policy: string): Map<string, string[]> {
  return new Map(policy.split(';').map((directive) => directive.trim().split(/\s+/)).map(([name, ...sources]) => [name ?? '', sources]))
}

describe('the security headers of the web app', () => {
  const panel = headers.documents.panel
  const policy = renderContentSecurityPolicy(panel)
  const sources = directives(policy)

  it('fit the response headers policy of CloudFront and open no inline or evaluated script', () => {
    assert.ok(policy.length <= CLOUDFRONT_POLICY_LIMIT, `${policy.length} characters`)
    assert.deepEqual(sources.get('default-src'), ["'none'"])
    assert.deepEqual(sources.get('frame-ancestors'), ["'none'"])
    assert.deepEqual(sources.get('object-src'), ["'none'"])
    assert.deepEqual(sources.get('base-uri'), ["'none'"])
    assert.ok(!policy.includes("'unsafe-eval'"))
    assert.ok(!(sources.get('script-src') ?? []).includes("'unsafe-inline'"))
    assert.ok(!(sources.get('style-src') ?? []).includes("'unsafe-inline'"))
  })

  it('allow the inline scripts of index.html by their hash, those and no other', () => {
    const hashes = (sources.get('script-src') ?? []).filter((source) => source.startsWith("'sha256-"))
    assert.deepEqual(hashes.sort(), inlineScriptHashes().sort())
  })

  it('give a reason for every source beyond the origin itself and for the choices of the panel', () => {
    for (const [directive, allowed] of sources) {
      for (const source of allowed.filter((candidate) => !["'self'", "'none'"].includes(candidate))) {
        assert.ok(panel.reasons[`${directive} ${source}`] !== undefined || panel.reasons[directive] !== undefined, `${directive} ${source} has a reason`)
      }
    }

    for (const header of ['Referrer-Policy', 'Strict-Transport-Security']) {
      assert.ok((panel.reasons[header] ?? '').length >= 60, `${header} has a reason`)
    }
  })

  it('agree with the headers of the API: the same transport security, sniffing and framing everywhere', () => {
    assert.deepEqual(strictTransportSecurity(headers.common['Strict-Transport-Security']), { maxAgeSeconds: 31_536_000, includeSubDomains: true, preload: false })
    assert.equal(headers.common['X-Content-Type-Options'], 'nosniff')
    assert.equal(headers.common['X-Frame-Options'], 'DENY')
    assert.equal(renderContentSecurityPolicy(headers.documents.api), "default-src 'none'; frame-ancestors 'none'")
  })
})

describe('the routes of the web app (CloudFront Function)', () => {
  const cases = (JSON.parse(readFileSync(join(import.meta.dirname, '../edge/panel-routes.cases.json'), 'utf8')) as { cases: { uri: string, rewritten: string }[] }).cases
  const handler = panelRoutes()

  it('answer every path without an extension with index.html and leave files as they are: the cases the proxy passes too', () => {
    assert.ok(cases.length >= 10)

    for (const { uri, rewritten } of cases) {
      assert.equal(handler({ request: { uri } }).uri, rewritten, uri)
    }
  })

  it('are a plain script of the runtime cloudfront-js-2.0: a handler, no module syntax, nothing but the request', () => {
    const code = readFileSync(PANEL_ROUTES_CODE, 'utf8')
    assert.match(code, /^function handler\(event\) \{$/m)
    assert.doesNotMatch(code, /^\s*(import|export)\s/m)
    assert.doesNotMatch(code, /require\(|fetch\(|process\./)
  })
})

for (const name of ['local', 'prod'] as const) {
  const config = STAGES[name]
  const stacks = stageOf(name)
  const edge = template(stacks.edge)
  const distribution = distributionOf(stacks.edge)
  const prefix = `dian-einvoice-${name}-`
  const behaviours = [distribution.DefaultCacheBehavior, ...distribution.CacheBehaviors]
  const panelOrigin = distribution.Origins.find((origin) => origin.OriginAccessControlId !== undefined)
  const apiOrigin = distribution.Origins.find((origin) => origin.CustomOriginConfig !== undefined)

  describe(`edge of the stage ${name}`, () => {
    it('keeps the web app in a private, versioned bucket that only the distribution reads', () => {
      const buckets = edge.findResources('AWS::S3::Bucket') as Record<string, { Properties: { BucketName: string, PublicAccessBlockConfiguration: Record<string, boolean>, VersioningConfiguration?: { Status: string }, LoggingConfiguration?: unknown, BucketEncryption: unknown } }>
      const [panelId, panel] = Object.entries(buckets).find(([, bucket]) => bucket.Properties.BucketName === `${prefix}panel-${config.account}-${config.region}`) ?? []
      assert.ok(panelId !== undefined && panel !== undefined)
      assert.deepEqual(panel.Properties.PublicAccessBlockConfiguration, { BlockPublicAcls: true, BlockPublicPolicy: true, IgnorePublicAcls: true, RestrictPublicBuckets: true })
      assert.equal(panel.Properties.VersioningConfiguration?.Status, 'Enabled')
      assert.ok(panel.Properties.BucketEncryption !== undefined && panel.Properties.LoggingConfiguration !== undefined)

      const policy = Object.values(edge.findResources('AWS::S3::BucketPolicy')).find((candidate) => JSON.stringify((candidate as { Properties: { Bucket: unknown } }).Properties.Bucket) === JSON.stringify({ Ref: panelId })) as { Properties: { PolicyDocument: { Statement: { Effect: string, Principal: unknown, Action: unknown, Condition?: Record<string, Record<string, unknown>> }[] } } }
      const allows = policy.Properties.PolicyDocument.Statement.filter((statement) => statement.Effect === 'Allow')
      assert.deepEqual(allows.map((statement) => statement.Principal), [{ Service: 'cloudfront.amazonaws.com' }])
      assert.deepEqual([allows[0]?.Action].flat().sort(), ['s3:GetObject', 's3:ListBucket'])
      assert.match(JSON.stringify(allows[0]?.Condition?.StringEquals?.['AWS:SourceArn']), /distribution\/.*Distribution/)
      assert.ok(policy.Properties.PolicyDocument.Statement.some((statement) => statement.Effect === 'Deny' && JSON.stringify(statement.Condition) === '{"Bool":{"aws:SecureTransport":"false"}}'), 'TLS only')
      assert.ok(panelOrigin !== undefined)
      edge.resourceCountIs('AWS::CloudFront::OriginAccessControl', 1)
      edge.hasResourceProperties('AWS::CloudFront::OriginAccessControl', { OriginAccessControlConfig: { OriginAccessControlOriginType: 's3', SigningBehavior: 'always', SigningProtocol: 'sigv4' } })
    })

    it('serves over HTTPS only: the web app redirects there, the API refuses anything else', () => {
      assert.equal(distribution.DefaultCacheBehavior.ViewerProtocolPolicy, 'redirect-to-https')

      for (const behaviour of distribution.CacheBehaviors) {
        assert.equal(behaviour.ViewerProtocolPolicy, apiPaths.includes(behaviour.PathPattern ?? '') ? 'https-only' : 'redirect-to-https', behaviour.PathPattern ?? 'default')
      }

      assert.equal(apiOrigin?.CustomOriginConfig?.OriginProtocolPolicy, 'https-only')
      assert.deepEqual(apiOrigin?.CustomOriginConfig?.OriginSSLProtocols, ['TLSv1.2'])
    })

    it('speaks HTTP/2 and HTTP/3 over IPv4 and IPv6, from every edge location, South America included, and logs every request', () => {
      assert.equal(distribution.HttpVersion, 'http2and3')
      assert.equal(distribution.IPV6Enabled, true)
      assert.equal(distribution.PriceClass, 'PriceClass_All')
      assert.deepEqual((distribution.Logging as { IncludeCookies: boolean, Prefix: string }).IncludeCookies, false)
      edge.hasResourceProperties('AWS::S3::Bucket', {
        BucketName: `${prefix}edge-logs-${config.account}-${config.region}`,
        OwnershipControls: { Rules: [{ ObjectOwnership: 'BucketOwnerPreferred' }] },
        LifecycleConfiguration: { Rules: [{ ExpirationInDays: config.edge.logsExpireAfter.toDays(), Status: 'Enabled' }] },
      })
    })

    it('sends the paths of the API to API Gateway, uncached, with every method, header, cookie and query, and the secret of the edge', () => {
      const api = distribution.CacheBehaviors.filter((behaviour) => behaviour.TargetOriginId === apiOrigin?.Id)
      assert.deepEqual(api.map((behaviour) => behaviour.PathPattern), [...apiPaths])

      for (const behaviour of api) {
        assert.equal(behaviour.CachePolicyId, CACHING_DISABLED)
        assert.equal(behaviour.OriginRequestPolicyId, ALL_VIEWER_EXCEPT_HOST_HEADER)
        assert.deepEqual([...behaviour.AllowedMethods].sort(), ['DELETE', 'GET', 'HEAD', 'OPTIONS', 'PATCH', 'POST', 'PUT'])
        // The API's responses keep the headers of the application, neither duplicated nor contradicted.
        assert.equal(behaviour.ResponseHeadersPolicyId, undefined)
        assert.equal(behaviour.Compress, false)
        assert.equal(behaviour.FunctionAssociations, undefined)
      }

      // The HTTP API of the functions, by the id the serverless service publishes.
      assert.match(JSON.stringify(apiOrigin?.DomainName), /\.execute-api\.us-east-1\.amazonaws\.com/)
      const parameters = edge.toJSON() as { Parameters: Record<string, { Type: string, Default?: string, NoEcho?: boolean }> }
      assert.ok(Object.values(parameters.Parameters).some((parameter) => parameter.Default === `/dian-einvoice/${name}/${HTTP_API_PARAMETER}`))

      // The secret of the edge: a parameter of the deployment locally, a dynamic reference to Secrets Manager in prod.
      const header = apiOrigin?.OriginCustomHeaders?.find((candidate) => candidate.HeaderName === ORIGIN_HEADER)
      assert.ok(header !== undefined)

      if (config.edge.originSecret === 'parameter') {
        assert.deepEqual(header.HeaderValue, { Ref: 'OriginVerifyValue' })
        assert.equal(parameters.Parameters.OriginVerifyValue?.NoEcho, true)
      } else {
        assert.match(JSON.stringify(header.HeaderValue), new RegExp(`\\{\\{resolve:secretsmanager:${originSecretName(config)}:SecretString:::`))
        assert.match(JSON.stringify(header.HeaderValue), /"Ref":"OriginVerifyVersion"/)
        assert.equal(parameters.Parameters.OriginVerifyVersion?.Default, '')
      }
    })

    it('caches the files whose names carry their hash for a year, as the upload says, and never index.html', () => {
      const assets = distribution.CacheBehaviors.find((behaviour) => behaviour.PathPattern === FINGERPRINTED_PATH)
      assert.ok(assets !== undefined)
      assert.deepEqual(assets.CachePolicyId, distribution.DefaultCacheBehavior.CachePolicyId)
      assert.equal(assets.Compress, true)
      assert.equal(distribution.DefaultCacheBehavior.Compress, true)
      edge.hasResourceProperties('AWS::CloudFront::CachePolicy', {
        CachePolicyConfig: {
          MinTTL: 0,
          DefaultTTL: 0,
          MaxTTL: 31_536_000,
          ParametersInCacheKeyAndForwardedToOrigin: { EnableAcceptEncodingGzip: true, EnableAcceptEncodingBrotli: true, HeadersConfig: { HeaderBehavior: 'none' }, CookiesConfig: { CookieBehavior: 'none' }, QueryStringsConfig: { QueryStringBehavior: 'none' } },
        },
      })
      assert.equal(distribution.DefaultRootObject, 'index.html')
    })

    it('answers the routes of the web app with its function on the default behaviour only, never with custom error responses', () => {
      assert.deepEqual(distribution.DefaultCacheBehavior.FunctionAssociations?.map((association) => association.EventType), ['viewer-request'])
      assert.deepEqual(behaviours.filter((behaviour) => behaviour.FunctionAssociations !== undefined).length, 1)
      assert.equal(distribution.CustomErrorResponses, undefined)
      edge.hasResourceProperties('AWS::CloudFront::Function', { Name: `${prefix}panel-routes`, AutoPublish: true, FunctionCode: readFileSync(PANEL_ROUTES_CODE, 'utf8'), FunctionConfig: { Runtime: 'cloudfront-js-2.0' } })
    })

    it('gives the web app the security headers of security-headers.json, and its files too', () => {
      const panel = headers.documents.panel
      const hsts = strictTransportSecurity(headers.common['Strict-Transport-Security'])
      edge.hasResourceProperties('AWS::CloudFront::ResponseHeadersPolicy', {
        ResponseHeadersPolicyConfig: {
          Name: `${prefix}panel`,
          SecurityHeadersConfig: {
            ContentSecurityPolicy: { ContentSecurityPolicy: renderContentSecurityPolicy(panel), Override: true },
            ContentTypeOptions: { Override: true },
            FrameOptions: { FrameOption: headers.common['X-Frame-Options'], Override: true },
            ReferrerPolicy: { ReferrerPolicy: panel.headers['Referrer-Policy'], Override: true },
            StrictTransportSecurity: { AccessControlMaxAgeSec: hsts.maxAgeSeconds, IncludeSubdomains: hsts.includeSubDomains, Preload: hsts.preload, Override: true },
          },
          CustomHeadersConfig: { Items: [{ Header: 'Permissions-Policy', Value: panel.headers['Permissions-Policy'], Override: true }] },
        },
      })
      edge.resourceCountIs('AWS::CloudFront::ResponseHeadersPolicy', 1)

      for (const behaviour of [distribution.DefaultCacheBehavior, ...distribution.CacheBehaviors.filter((candidate) => candidate.PathPattern === FINGERPRINTED_PATH)]) {
        assert.ok(behaviour.ResponseHeadersPolicyId !== undefined)
      }
    })

    it(name === 'prod' ? 'puts a firewall in front: the managed rules of AWS and limits per address, stricter on the sign-in points' : 'has no firewall, which the emulator would not apply', () => {
      if (config.edge.firewall === null) {
        edge.resourceCountIs('AWS::WAFv2::WebACL', 0)
        assert.equal(distribution.WebACLId, undefined)

        return
      }

      const firewalls = Object.entries(edge.findResources('AWS::WAFv2::WebACL')) as [string, { Properties: { Scope: string, DefaultAction: unknown, Rules: { Name: string, Priority: number, Action?: unknown, OverrideAction?: unknown, Statement: Record<string, Record<string, unknown>> }[] } }][]
      assert.equal(firewalls.length, 1)
      const [id, firewall] = firewalls[0] as [string, { Properties: { Scope: string, DefaultAction: unknown, Rules: { Name: string, Priority: number, Action?: unknown, OverrideAction?: unknown, Statement: Record<string, Record<string, unknown>> }[] } }]
      assert.equal(firewall.Properties.Scope, 'CLOUDFRONT')
      assert.deepEqual(firewall.Properties.DefaultAction, { Allow: {} })
      assert.deepEqual(distribution.WebACLId, { 'Fn::GetAtt': [id, 'Arn'] })

      const managed = firewall.Properties.Rules.filter((rule) => rule.Statement.ManagedRuleGroupStatement !== undefined)
      assert.deepEqual(managed.map((rule) => rule.Statement.ManagedRuleGroupStatement?.Name), ['AWSManagedRulesAmazonIpReputationList', 'AWSManagedRulesCommonRuleSet', 'AWSManagedRulesKnownBadInputsRuleSet'])
      const counted = managed.flatMap((rule) => ((rule.Statement.ManagedRuleGroupStatement?.RuleActionOverrides ?? []) as { Name: string, ActionToUse: unknown }[]).map((override) => [override.Name, override.ActionToUse]))
      // Bodies of more than 8 KB (invoices with many lines, the signing certificate) and clients without a User-Agent are legitimate.
      assert.deepEqual(counted, [['SizeRestrictions_BODY', { Count: {} }], ['NoUserAgent_HEADER', { Count: {} }]])

      const limits = firewall.Properties.Rules.filter((rule) => rule.Statement.RateBasedStatement !== undefined).map((rule) => rule.Statement.RateBasedStatement as { Limit: number, EvaluationWindowSec: number, AggregateKeyType: string, ScopeDownStatement?: unknown })
      assert.deepEqual(limits.map((limit) => [limit.Limit, limit.EvaluationWindowSec, limit.AggregateKeyType]), [[config.edge.firewall.authenticationRequestsPerAddress, 300, 'IP'], [config.edge.firewall.requestsPerAddress, 300, 'IP']])
      assert.ok(config.edge.firewall.authenticationRequestsPerAddress < config.edge.firewall.requestsPerAddress)
      assert.match(JSON.stringify(limits[0]?.ScopeDownStatement), /"SearchString":"POST"/)
      assert.ok(JSON.stringify(limits[0]?.ScopeDownStatement).includes(JSON.stringify(authenticationRegex(authenticationPaths)).slice(1, -1)))
      assert.deepEqual(firewall.Properties.Rules.map((rule) => rule.Priority), [0, 1, 2, 3, 4])
    })

    it('publishes the distribution and the bucket of the web app, for the upload and the proxy of the emulated cloud', () => {
      for (const parameter of ['distribution-id', 'distribution-domain-name', 'panel-bucket-name']) {
        edge.hasResourceProperties('AWS::SSM::Parameter', { Name: `/dian-einvoice/${name}/edge/${parameter}` })
      }
    })

    it('deploys after the stacks whose secret and roles the functions it fronts read', () => {
      assert.deepEqual(stacks.edge.dependencies.map((stack) => stack.stackName), [`${prefix}compute`])
    })
  })
}

describe('the domain of the edge of prod, a parameter of the deployment', () => {
  it('serves its own name with its certificate and TLS 1.2 at least', () => {
    const domain = STAGES.prod.edge.domain
    assert.ok(domain !== null)
    const distribution = distributionOf(stageOf('prod').edge)
    assert.deepEqual(distribution.Aliases, [domain.name])
    assert.deepEqual(distribution.ViewerCertificate, { AcmCertificateArn: domain.certificateArn, MinimumProtocolVersion: 'TLSv1.2_2021', SslSupportMethod: 'sni-only' })
  })

  it('without a domain, the name CloudFront gives the distribution and its default certificate, acknowledged in cdk-nag', () => {
    const config: StageConfig = { ...STAGES.prod, edge: { ...STAGES.prod.edge, domain: null } }
    const { app, stacks } = variantOf(config)
    const distribution = distributionOf(stacks.edge)
    assert.equal(distribution.Aliases, undefined)
    assert.ok(distribution.ViewerCertificate === undefined || distribution.ViewerCertificate.CloudFrontDefaultCertificate === true)

    const nag = validationReport(app.synth().directory, 'AwsSolutions')
    assert.deepEqual(nag?.violations, [])
    assert.ok(nag?.suppressedViolations.some((violation) => violation.ruleName === 'AwsSolutions-CFR4' && violation.violatingConstructs.some((construct) => construct.constructPath === 'prod/edge/Distribution/Resource')))
  })

  it('takes the name and the certificate from the context of the CDK CLI, without a lookup in an account', () => {
    const app = (context: Record<string, string>) => new App({ context: { ...cdkContext(), ...context } })
    const certificate = 'arn:aws:acm:us-east-1:123456789012:certificate/11111111-2222-3333-4444-555555555555'
    assert.deepEqual(withEdgeDomain(STAGES.prod, app({ edgeDomainName: 'invoices.example.org', edgeCertificateArn: certificate })).edge.domain, { name: 'invoices.example.org', certificateArn: certificate })
    assert.equal(withEdgeDomain(STAGES.prod, app({ edgeDomainName: '' })).edge.domain, null)
    assert.equal(withEdgeDomain(STAGES.prod, app({})).edge.domain, STAGES.prod.edge.domain)
    assert.equal(withEdgeDomain(STAGES.local, app({ edgeDomainName: 'invoices.example.org', edgeCertificateArn: certificate })).edge.domain, null)
    assert.throws(() => withEdgeDomain(STAGES.prod, app({ edgeDomainName: 'invoices.example.org', edgeCertificateArn: 'arn:aws:acm:eu-west-1:123456789012:certificate/1' })), /us-east-1/)

    const built = buildApp({ stages: ['prod'], context: { ...cdkContext(), edgeDomainName: 'invoices.example.org', edgeCertificateArn: certificate } })
    assert.deepEqual(distributionOf((built.stages[0] as ReturnType<typeof stageOf>).edge).Aliases, ['invoices.example.org'])
  })
})

describe('the sign-in points the firewall limits', () => {
  const pattern = new RegExp(authenticationRegex(authenticationPaths))

  it('are matched by its expression, each route with its parameters as one segment, and nothing else of the API', () => {
    for (const path of ['/api/v1/auth/login', '/api/v1/auth/register', '/api/v1/auth/forgot-password', '/api/v1/auth/reset-password', '/api/v1/invitations/abc123/accept']) {
      assert.match(path, pattern)
    }

    for (const path of ['/api/v1/auth/me', '/api/v1/auth/logout', '/api/v1/invoices', '/api/v1/invitations/abc/def/accept', '/api/v1/auth/login/x']) {
      assert.doesNotMatch(path, pattern)
    }
  })
})
