#!/bin/sh
# make dian-schematron, inside the throwaway schematron container (compose profile tools): the
# schematron of the DIAN toolbox over the demo invoice, credit note and debit note of Demo Comercial
# SAS (synthetic NIT 990000001; run make demo first). SaxonJS (XSLT 3.0, from npm) and SchXslt
# (schematron compiler, from Maven Central) are downloaded once into the schematron-tools volume
# mounted at /home/node, with the compiled rules; nothing goes into the repo or an image.
set -eu

HOME_DIR=/home/node
TOOLS=$HOME_DIR/tools
SCHXSLT=$HOME_DIR/schxslt
BUILD=$HOME_DIR/build
SAXONJS_VERSION=2.7.0
SCHXSLT_VERSION=1.10.1
MODEL=/toolbox/XSL/DIAN-UBL21-model-compiled.xsl
DEMO_NIT=990000001

if [ ! -f "$MODEL" ]; then
  echo "The DIAN toolbox is not in backend/storage/app/dian-toolbox (see backend/resources/dian/SOURCE.txt)." >&2
  exit 2
fi

if [ ! -f "$TOOLS/node_modules/saxon-js/package.json" ]; then
  echo "Downloading SaxonJS $SAXONJS_VERSION (xslt3 and saxon-js, npm) into the schematron-tools volume..."
  npm install --prefix "$TOOLS" --no-audit --no-fund --loglevel=error "xslt3@$SAXONJS_VERSION" "saxon-js@$SAXONJS_VERSION" >/dev/null
fi

if [ ! -d "$SCHXSLT/xslt" ]; then
  echo "Downloading SchXslt $SCHXSLT_VERSION (Maven Central) into the schematron-tools volume..."
  mkdir -p "$SCHXSLT"
  jar="schxslt-$SCHXSLT_VERSION.jar"
  wget -q -O "$SCHXSLT/$jar" "https://repo1.maven.org/maven2/name/dmaus/schxslt/schxslt/$SCHXSLT_VERSION/$jar"
  wget -q -O "$SCHXSLT/$jar.sha1" "https://repo1.maven.org/maven2/name/dmaus/schxslt/schxslt/$SCHXSLT_VERSION/$jar.sha1"
  echo "$(cut -c1-40 "$SCHXSLT/$jar.sha1")  $SCHXSLT/$jar" | sha1sum -c >/dev/null
  (cd "$SCHXSLT" && unzip -q "$jar")
fi

XSLT3="$TOOLS/node_modules/.bin/xslt3"
mkdir -p "$BUILD"

# Rules compiled once, again only when their source changes.
if [ ! -f "$BUILD/model.sef.json" ] || [ "$MODEL" -nt "$BUILD/model.sef.json" ]; then
  echo "Compiling the official rules (XSL/DIAN-UBL21-model-compiled.xsl)..."
  "$XSLT3" -xsl:"$MODEL" -export:"$BUILD/model.sef.json" -nogo
fi

if [ ! -f "$BUILD/codelists.sef.json" ] || [ /check/codelists.sch -nt "$BUILD/codelists.sef.json" ]; then
  echo "Compiling the code lists (Schemes/listacodigos v1.6) with SchXslt..."
  "$XSLT3" -xsl:"$SCHXSLT/xslt/2.0/pipeline-for-svrl.xsl" -s:/check/codelists.sch -o:"$BUILD/codelists.xsl"
  "$XSLT3" -xsl:"$BUILD/codelists.xsl" -export:"$BUILD/codelists.sef.json" -nogo
fi

# The documents of Demo Comercial SAS: the first invoice with a line discount and ReteIVA, and its
# first credit note and debit note, as make demo stores them.
company=$(grep -l ">$DEMO_NIT</cbc:CompanyID>" /documents/*/invoice/*.xml 2>/dev/null | head -1 | xargs -r dirname | xargs -r dirname)

if [ -z "$company" ]; then
  echo "No demo documents of Demo Comercial SAS: run make demo first." >&2
  exit 2
fi

invoice=$(ls "$company"/invoice/*.xml | grep -v '\.unsigned\.xml$' | xargs -r grep -l '<cbc:ChargeIndicator>false</cbc:ChargeIndicator>' | xargs -r grep -l '<cbc:ID>05</cbc:ID>' | head -1)
credit=$(ls "$company"/credit_note/*.xml | grep -v '\.unsigned\.xml$' | head -1)
debit=$(ls "$company"/debit_note/*.xml | grep -v '\.unsigned\.xml$' | head -1)

if [ -z "$invoice" ] || [ -z "$credit" ] || [ -z "$debit" ]; then
  echo "The demo documents are incomplete (invoice with a discount and ReteIVA, credit note, debit note): run make demo first." >&2
  exit 2
fi

node /check/check.mjs "$TOOLS" "$BUILD/model.sef.json" "$BUILD/codelists.sef.json" \
  "invoice=$invoice" "credit-note=$credit" "debit-note=$debit"
