<?xml version="1.0" encoding="UTF-8"?>
<!--
  Wrapper for make dian-schematron: the code-list pattern of the DIAN toolbox (Schemes/listacodigos,
  v1.6 of 2019) with the namespaces of Schemes/DIAN-UBL21-model.sch, which includes a v2.0 that the
  toolbox does not ship. /toolbox is the toolbox folder, mounted read-only by compose.
-->
<schema xmlns="http://purl.oclc.org/dsdl/schematron" queryBinding="xslt3">
  <ns prefix="xs" uri="http://www.w3.org/2001/XMLSchema"/>
  <ns prefix="ubl" uri="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"/>
  <ns prefix="cn" uri="urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2"/>
  <ns prefix="dn" uri="urn:oasis:names:specification:ubl:schema:xsd:DebitNote-2"/>
  <ns prefix="app" uri="urn:oasis:names:specification:ubl:schema:xsd:ApplicationResponse-2"/>
  <ns prefix="ext" uri="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2"/>
  <ns prefix="cbc" uri="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2"/>
  <ns prefix="cac" uri="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"/>
  <ns prefix="sts" uri="dian:gov:co:facturaelectronica:Structures-2-1"/>
  <ns prefix="ds" uri="http://www.w3.org/2000/09/xmldsig#"/>
  <ns prefix="xades" uri="http://uri.etsi.org/01903/v1.3.2#"/>
  <ns prefix="xades141" uri="http://uri.etsi.org/01903/v1.4.1#"/>
  <include href="/toolbox/Schemes/listacodigos/DIAN_UBL21-listacodigos_v1.6.sch"/>
</schema>
