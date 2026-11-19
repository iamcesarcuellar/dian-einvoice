<?php

namespace App\Support\Xml;

use RuntimeException;

/** XML that SafeXml does not parse: empty, not well formed, or with a DTD. */
final class XmlRefused extends RuntimeException {}
