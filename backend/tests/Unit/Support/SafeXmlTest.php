<?php

namespace Tests\Unit\Support;

use App\Support\Xml\SafeXml;
use App\Support\Xml\XmlRefused;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every XML the backend parses goes through SafeXml: what it signs and validates, and the DIAN's
 * responses. A DTD is refused before libxml reads it and again after, so no external entity is read
 * and no entity is expanded; plain UBL still parses.
 */
final class SafeXmlTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function hostile(): iterable
    {
        yield 'external entity (XXE)' => ['<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file:///etc/passwd">]><r>&x;</r>'];
        yield 'external DTD over the network' => ['<?xml version="1.0"?><!DOCTYPE r SYSTEM "http://127.0.0.1:9/evil.dtd"><r>ok</r>'];
        yield 'parameter entity' => ['<?xml version="1.0"?><!DOCTYPE r [<!ENTITY % p SYSTEM "http://127.0.0.1:9/p.dtd"> %p;]><r/>'];
        yield 'entity expansion (billion laughs)' => ['<?xml version="1.0"?><!DOCTYPE r [<!ENTITY a "aaaaaaaaaa"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;&a;&a;"><!ENTITY c "&b;&b;&b;&b;&b;&b;&b;&b;&b;&b;"><!ENTITY d "&c;&c;&c;&c;&c;&c;&c;&c;&c;&c;"><!ENTITY e "&d;&d;&d;&d;&d;&d;&d;&d;&d;&d;"><!ENTITY f "&e;&e;&e;&e;&e;&e;&e;&e;&e;&e;">]><r>&f;</r>'];
        yield 'lower-case doctype' => ['<?xml version="1.0"?><!doctype r [<!ENTITY x "y">]><r>&x;</r>'];
        yield 'doctype in UTF-16, seen only once parsed' => [mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE r [<!ENTITY x "y">]><r>&x;</r>', 'UTF-16')];
        yield 'not well formed' => ['<r><unclosed></r>'];
        yield 'empty' => [''];
    }

    #[DataProvider('hostile')]
    public function test_hostile_xml_is_refused(string $xml): void
    {
        $this->expectException(XmlRefused::class);

        SafeXml::load($xml);
    }

    public function test_a_document_without_a_dtd_parses_with_its_whitespace(): void
    {
        $dom = SafeXml::load("<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<Invoice xmlns=\"urn:oasis:names:specification:ubl:schema:xsd:Invoice-2\">\n  <ID>SETP990000001</ID>\n</Invoice>");

        $this->assertSame('Invoice', $dom->documentElement?->localName);
        $this->assertStringContainsString("\n  <ID>", (string) $dom->saveXML());
    }
}
