// The edge (Infrastructure task 4): one CloudFront distribution in front of the web app and the API, under one
// domain. The web app is served from a private, versioned bucket that only the distribution reads (origin access
// control); the paths of the API (backend/resources/http/paths.json) go to API Gateway of the functions, uncached,
// with every header but Host, every cookie and every query string, and the secret of the edge in X-Origin-Verify,
// which the origin verifier of API Gateway checks. A CloudFront Function, on the default behaviour only, answers
// the routes of the web app with index.html (never custom error responses, which would cover the errors of the API
// too). The security headers of the web app come from their single source of truth (security-headers.json); the
// answers of the API keep those of the application. HTTPS only, HTTP/2 and HTTP/3, compression for the web app,
// access logs kept a while, and in prod a domain of its own with TLS 1.2 at least and a firewall (AWS WAF). The
// reasons of each setting are in docs/infrastructure.md.
import { resolve } from 'node:path'
import { Annotations, CfnParameter, Duration, SecretValue, Stack, type StackProps, Validations } from 'aws-cdk-lib'
import { Certificate } from 'aws-cdk-lib/aws-certificatemanager'
import {
  AccessLevel,
  AllowedMethods,
  CacheCookieBehavior,
  CacheHeaderBehavior,
  CachePolicy,
  CacheQueryStringBehavior,
  CachedMethods,
  Distribution,
  Function as EdgeFunction,
  FunctionCode,
  FunctionEventType,
  FunctionRuntime,
  HeadersFrameOption,
  HeadersReferrerPolicy,
  HttpVersion,
  OriginProtocolPolicy,
  OriginRequestPolicy,
  OriginSslPolicy,
  PriceClass,
  ResponseHeadersPolicy,
  SecurityPolicyProtocol,
  SSLMethod,
  ViewerProtocolPolicy,
  type BehaviorOptions,
} from 'aws-cdk-lib/aws-cloudfront'
import { HttpOrigin, S3BucketOrigin } from 'aws-cdk-lib/aws-cloudfront-origins'
import { BlockPublicAccess, Bucket, BucketEncryption, ObjectOwnership } from 'aws-cdk-lib/aws-s3'
import { StringParameter } from 'aws-cdk-lib/aws-ssm'
import { CfnWebACL } from 'aws-cdk-lib/aws-wafv2'
import type { Construct } from 'constructs'
import { Published } from './published.ts'
import { renderContentSecurityPolicy, strictTransportSecurity, type SecurityHeaders } from './security-headers.ts'
import { bucketName, HTTP_API_PARAMETER, originSecretName, parameterPrefix, removalPolicy, resourceName, type FirewallSettings, type StageConfig } from './stage.ts'

export interface EdgeStackProps extends StackProps {
  readonly stage: StageConfig
  readonly headers: SecurityHeaders
  // The path patterns of the API, and its sign-in points (POST routes of the application, as Laravel writes them).
  readonly apiPaths: readonly string[]
  readonly authenticationPaths: readonly string[]
}

/** The header the distribution adds to every request to API Gateway, which the origin verifier checks. */
export const ORIGIN_HEADER = 'X-Origin-Verify'

/** The files of the build of the web app whose names carry a hash of their content (Vite writes them there). */
export const FINGERPRINTED_PATH = '/assets/*'

/** The code of the CloudFront Function of the routes of the web app. */
export const PANEL_ROUTES_CODE = resolve(import.meta.dirname, '../edge/panel-routes.js')

export class EdgeStack extends Stack {
  readonly distribution: Distribution
  readonly panel: Bucket
  readonly logs: Bucket
  readonly firewall: CfnWebACL | null

  constructor(scope: Construct, id: string, props: EdgeStackProps) {
    super(scope, id, props)
    const { stage, headers } = props
    const published = new Published(this, stage, 'edge')
    const settings = stage.edge

    // The access logs of the distribution (standard logs, which CloudFront writes with ACLs, so the bucket allows
    // them) and the server access logs of the bucket of the web app. No cookie is logged: they carry the session.
    this.logs = new Bucket(this, 'Logs', {
      bucketName: bucketName(stage, 'edge-logs'),
      encryption: BucketEncryption.S3_MANAGED,
      blockPublicAccess: BlockPublicAccess.BLOCK_ALL,
      objectOwnership: ObjectOwnership.BUCKET_OWNER_PREFERRED,
      enforceSSL: true,
      lifecycleRules: [{ id: 'expire-edge-logs', expiration: settings.logsExpireAfter, abortIncompleteMultipartUploadAfter: Duration.days(1) }],
      removalPolicy: removalPolicy(stage),
    })

    // The build of the web app, private, served only by the distribution. Versioned: an upload that replaces a
    // file keeps the earlier version for a while, to go back to it (docs/infrastructure.md).
    this.panel = new Bucket(this, 'Panel', {
      bucketName: bucketName(stage, 'panel'),
      encryption: BucketEncryption.S3_MANAGED,
      bucketKeyEnabled: true,
      blockPublicAccess: BlockPublicAccess.BLOCK_ALL,
      objectOwnership: ObjectOwnership.BUCKET_OWNER_ENFORCED,
      enforceSSL: true,
      versioned: true,
      serverAccessLogsBucket: this.logs,
      serverAccessLogsPrefix: 'panel/',
      lifecycleRules: [
        { id: 'expire-replaced-versions', noncurrentVersionExpiration: settings.panelVersionsExpireAfter, abortIncompleteMultipartUploadAfter: Duration.days(1) },
        { id: 'remove-expired-delete-markers', expiredObjectDeleteMarker: true },
      ],
      removalPolicy: removalPolicy(stage),
    })

    // Read and list: a file that does not exist answers 404, not 403.
    const panelOrigin = S3BucketOrigin.withOriginAccessControl(this.panel, { originAccessLevels: [AccessLevel.READ, AccessLevel.LIST] })

    // The cache of the web app follows the Cache-Control of each file, which the upload sets: a year, immutable,
    // for the files whose names carry their hash; no-cache for index.html and the rest, so a new build shows at
    // once. A file without one is not cached at all. Nothing of the request is in the key but its path and the
    // compression the viewer accepts.
    const panelCache = new CachePolicy(this, 'PanelCache', {
      cachePolicyName: resourceName(stage, 'panel'),
      comment: 'The web app: what each file says (Cache-Control), compressed',
      minTtl: Duration.seconds(0),
      defaultTtl: Duration.seconds(0),
      maxTtl: Duration.days(365),
      headerBehavior: CacheHeaderBehavior.none(),
      cookieBehavior: CacheCookieBehavior.none(),
      queryStringBehavior: CacheQueryStringBehavior.none(),
      enableAcceptEncodingGzip: true,
      enableAcceptEncodingBrotli: true,
    })

    const panelHeaders = new ResponseHeadersPolicy(this, 'PanelHeaders', panelHeadersOf(stage, headers))

    // The routes of the web app answered with index.html; files keep their path. Viewer request, default behaviour.
    const panelRoutes = new EdgeFunction(this, 'PanelRoutes', {
      functionName: resourceName(stage, 'panel-routes'),
      comment: 'Answers the routes of the web app with index.html',
      code: FunctionCode.fromFile({ filePath: PANEL_ROUTES_CODE }),
      runtime: FunctionRuntime.JS_2_0,
    })

    const panel: BehaviorOptions = {
      origin: panelOrigin,
      viewerProtocolPolicy: ViewerProtocolPolicy.REDIRECT_TO_HTTPS,
      allowedMethods: AllowedMethods.ALLOW_GET_HEAD,
      cachedMethods: CachedMethods.CACHE_GET_HEAD,
      cachePolicy: panelCache,
      responseHeadersPolicy: panelHeaders,
      compress: true,
    }

    // The API Gateway of the functions (its id, published by the serverless service, deployed before this stack),
    // over HTTPS with TLS 1.2. The secret of the edge goes with every request; CloudFront replaces a header of
    // that name a viewer sends.
    const apiId = StringParameter.valueForStringParameter(this, `${parameterPrefix(stage)}/${HTTP_API_PARAMETER}`)
    const apiOrigin = new HttpOrigin(`${apiId}.execute-api.${stage.region}.amazonaws.com`, {
      protocolPolicy: OriginProtocolPolicy.HTTPS_ONLY,
      originSslProtocols: [OriginSslPolicy.TLS_V1_2],
      readTimeout: Duration.seconds(30),
      customHeaders: { [ORIGIN_HEADER]: this.originSecret(stage) },
    })

    // Never cached, every method, every header but Host (API Gateway answers by its own name), every cookie and
    // query string; CloudFront appends the address of the client to X-Forwarded-For, which the application counts
    // by (backend/config/edge.php). No response headers policy: the answers of the API carry the headers of the
    // application, neither duplicated nor contradicted.
    const api: BehaviorOptions = {
      origin: apiOrigin,
      viewerProtocolPolicy: ViewerProtocolPolicy.HTTPS_ONLY,
      allowedMethods: AllowedMethods.ALLOW_ALL,
      cachedMethods: CachedMethods.CACHE_GET_HEAD,
      cachePolicy: CachePolicy.CACHING_DISABLED,
      originRequestPolicy: OriginRequestPolicy.ALL_VIEWER_EXCEPT_HOST_HEADER,
      compress: false,
    }

    this.firewall = settings.firewall === null ? null : this.webAcl(stage, settings.firewall, props.authenticationPaths)
    const domain = settings.domain

    this.distribution = new Distribution(this, 'Distribution', {
      comment: `dian-einvoice ${stage.name}: the web app and the API under one domain`,
      defaultBehavior: { ...panel, functionAssociations: [{ function: panelRoutes, eventType: FunctionEventType.VIEWER_REQUEST }] },
      additionalBehaviors: {
        [FINGERPRINTED_PATH]: panel,
        ...Object.fromEntries(props.apiPaths.map((path) => [path, api])),
      },
      defaultRootObject: 'index.html',
      httpVersion: HttpVersion.HTTP2_AND_3,
      enableIpv6: true,
      // The users are Colombian businesses: only the class of every edge location includes those of South America.
      priceClass: PriceClass.PRICE_CLASS_ALL,
      enableLogging: true,
      logBucket: this.logs,
      logFilePrefix: 'cloudfront/',
      logIncludesCookies: false,
      ...(domain === null
        ? {}
        : {
            domainNames: [domain.name],
            certificate: Certificate.fromCertificateArn(this, 'Certificate', domain.certificateArn),
            minimumProtocolVersion: SecurityPolicyProtocol.TLS_V1_2_2021,
            sslSupportMethod: SSLMethod.SNI,
          }),
      ...(this.firewall === null ? {} : { webAclId: this.firewall.attrArn }),
    })

    // Listing lets the bucket answer 404 for a file it does not hold. The root is index.html (defaultRootObject),
    // so no path asks the bucket for a listing, and the bucket holds the public build of the web app only.
    Annotations.of(this.distribution).acknowledgeWarning('@aws-cdk/aws-cloudfront-origins:listBucketSecurityRisk', 'The bucket holds the public build of the web app only, and its root is index.html (defaultRootObject).')

    Validations.of(this.distribution).acknowledge({
      id: 'AwsSolutions::AwsSolutions-CFR1',
      reason: 'No geographic restriction: the customers of the businesses that invoice and the software companies that integrate may be anywhere, and access is decided by the sign-in and the API keys, not by country.',
    })

    if (domain === null) {
      Validations.of(this.distribution).acknowledge({
        id: 'AwsSolutions::AwsSolutions-CFR4',
        reason: 'Without a domain of its own the distribution answers with the default certificate of CloudFront, whose security policy AWS sets to TLSv1 and allows no other. The stage prod runs with its domain, where TLSv1.2_2021 is the minimum; the emulated cloud serves plain HTTP through its proxy.',
      })
    }

    if (this.firewall === null) {
      Validations.of(this.distribution).acknowledge({
        id: 'AwsSolutions::AwsSolutions-CFR2',
        reason: 'The stage local runs in the emulator, which applies no AWS WAF; the stage prod associates its web ACL with the distribution.',
      })
    }

    published.add('DistributionId', 'distribution-id', this.distribution.distributionId, 'Id of the CloudFront distribution of the edge')
    published.add('DistributionDomainName', 'distribution-domain-name', this.distribution.distributionDomainName, 'Domain name CloudFront gives the distribution of the edge')
    published.add('PanelBucketName', 'panel-bucket-name', this.panel.bucketName, 'Bucket of the build of the web app, served by the edge')

    if (this.firewall !== null) {
      published.add('FirewallArn', 'firewall-arn', this.firewall.attrArn, 'ARN of the web ACL of AWS WAF of the edge')
    }
  }

  /**
   * The value of the secret of the edge for the custom header of the origin: a parameter of the deployment (the
   * emulator expands no dynamic reference outside RDS, so make cloud-up reads the secret and passes it), or a
   * dynamic reference that CloudFormation resolves from Secrets Manager. OriginVerifyVersion pins a version after
   * a rotation, so the distribution is updated with it (docs/infrastructure.md); empty, the current one.
   */
  private originSecret(stage: StageConfig): string {
    if (stage.edge.originSecret === 'parameter') {
      return new CfnParameter(this, 'OriginVerifyValue', {
        type: 'String',
        noEcho: true,
        minLength: 32,
        description: `The current value of the secret ${originSecretName(stage)}, which the distribution sends to API Gateway in ${ORIGIN_HEADER}`,
      }).valueAsString
    }

    const version = new CfnParameter(this, 'OriginVerifyVersion', {
      type: 'String',
      default: '',
      allowedPattern: '^[A-Za-z0-9-]*$',
      description: `The version of the secret ${originSecretName(stage)} the distribution sends to API Gateway in ${ORIGIN_HEADER}; empty, the current one. A rotation deploys the stack with the new version.`,
    }).valueAsString

    return SecretValue.secretsManager(originSecretName(stage), { versionId: version }).unsafeUnwrap()
  }

  /**
   * The firewall of the edge (AWS WAF, scope CloudFront): the managed rule groups of AWS and limits per address,
   * stricter on the sign-in points. Rules that would block legitimate requests of the API count instead, each with
   * its reason in docs/infrastructure.md. A blocked request gets 403; one over a limit, 429 with Retry-After.
   */
  private webAcl(stage: StageConfig, firewall: FirewallSettings, authenticationPaths: readonly string[]): CfnWebACL {
    const name = resourceName(stage, 'edge')
    const visibility = (metric: string): CfnWebACL.VisibilityConfigProperty => ({ cloudWatchMetricsEnabled: true, metricName: `${name}-${metric}`, sampledRequestsEnabled: true })
    const window = firewall.window.toSeconds()
    const managed = (group: string, priority: number, counted: readonly string[]): CfnWebACL.RuleProperty => ({
      name: `aws-${group}`,
      priority,
      overrideAction: { none: {} },
      statement: {
        managedRuleGroupStatement: {
          vendorName: 'AWS',
          name: group,
          ...(counted.length === 0 ? {} : { ruleActionOverrides: counted.map((rule) => ({ name: rule, actionToUse: { count: {} } })) }),
        },
      },
      visibilityConfig: visibility(group),
    })
    const limited: CfnWebACL.RuleActionProperty = { block: { customResponse: { responseCode: 429, customResponseBodyKey: 'too-many-requests', responseHeaders: [{ name: 'Retry-After', value: String(window) }] } } }

    return new CfnWebACL(this, 'Firewall', {
      name,
      description: `The firewall of the edge of dian-einvoice, stage ${stage.name}`,
      scope: 'CLOUDFRONT',
      defaultAction: { allow: {} },
      visibilityConfig: visibility('all'),
      customResponseBodies: {
        'too-many-requests': {
          contentType: 'APPLICATION_JSON',
          content: JSON.stringify({ type: 'about:blank', title: 'Too Many Requests', status: 429, detail: `Too many requests from this address; try again in ${window} seconds.` }),
        },
      },
      rules: [
        managed('AWSManagedRulesAmazonIpReputationList', 0, []),
        // Bodies over 8 KB are legitimate here (invoices with many lines, the signing certificate, up to 100 KB),
        // and clients without a User-Agent too (several HTTP libraries send none): both count.
        managed('AWSManagedRulesCommonRuleSet', 1, ['SizeRestrictions_BODY', 'NoUserAgent_HEADER']),
        managed('AWSManagedRulesKnownBadInputsRuleSet', 2, []),
        {
          name: 'authentication-per-address',
          priority: 3,
          action: limited,
          statement: {
            rateBasedStatement: {
              limit: firewall.authenticationRequestsPerAddress,
              evaluationWindowSec: window,
              aggregateKeyType: 'IP',
              scopeDownStatement: {
                andStatement: {
                  statements: [
                    { byteMatchStatement: { fieldToMatch: { method: {} }, positionalConstraint: 'EXACTLY', searchString: 'POST', textTransformations: [{ priority: 0, type: 'NONE' }] } },
                    { regexMatchStatement: { fieldToMatch: { uriPath: {} }, regexString: authenticationRegex(authenticationPaths), textTransformations: [{ priority: 0, type: 'NONE' }] } },
                  ],
                },
              },
            },
          },
          visibilityConfig: visibility('authentication-per-address'),
        },
        {
          name: 'requests-per-address',
          priority: 4,
          action: limited,
          statement: { rateBasedStatement: { limit: firewall.requestsPerAddress, evaluationWindowSec: window, aggregateKeyType: 'IP' } },
          visibilityConfig: visibility('requests-per-address'),
        },
      ],
    })
  }
}

/** The response headers policy of the web app: the common headers and those of the panel, from security-headers.json. */
export function panelHeadersOf(stage: StageConfig, headers: SecurityHeaders): ConstructorParameters<typeof ResponseHeadersPolicy>[2] {
  const panel = headers.documents.panel
  const hsts = strictTransportSecurity(headers.common['Strict-Transport-Security'])
  // The values of the file as the enums of CloudFront, compared as the strings they are.
  const frame = Object.values(HeadersFrameOption).find((option) => String(option) === headers.common['X-Frame-Options'])
  const referrer = Object.values(HeadersReferrerPolicy).find((policy) => String(policy) === panel.headers['Referrer-Policy'])

  if (headers.common['X-Content-Type-Options'] !== 'nosniff' || frame === undefined || referrer === undefined) {
    throw new Error('security-headers.json: X-Content-Type-Options must be nosniff, X-Frame-Options and the Referrer-Policy of the panel values CloudFront knows.')
  }

  const custom = Object.entries(panel.headers).filter(([name]) => name !== 'Referrer-Policy')

  return {
    responseHeadersPolicyName: resourceName(stage, 'panel'),
    comment: 'The security headers of the web app (backend/resources/http/security-headers.json)',
    securityHeadersBehavior: {
      contentSecurityPolicy: { contentSecurityPolicy: renderContentSecurityPolicy(panel), override: true },
      contentTypeOptions: { override: true },
      frameOptions: { frameOption: frame, override: true },
      referrerPolicy: { referrerPolicy: referrer, override: true },
      strictTransportSecurity: { accessControlMaxAge: Duration.seconds(hsts.maxAgeSeconds), includeSubdomains: hsts.includeSubDomains, preload: hsts.preload, override: true },
    },
    customHeadersBehavior: { customHeaders: custom.map(([header, value]) => ({ header, value, override: true })) },
  }
}

/** The sign-in points as one regular expression of AWS WAF over the path: each route with its parameters as one segment. */
export function authenticationRegex(paths: readonly string[]): string {
  const routes = paths.map((path) => path.split(/\{[A-Za-z0-9_]+\}/).map((part) => part.replace(/[.*+?^$()|[\]\\]/g, '\\$&')).join('[^/]+'))

  return `^(${routes.join('|')})$`
}
