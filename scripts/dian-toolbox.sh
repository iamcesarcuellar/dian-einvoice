#!/bin/sh
# make dian-toolbox, inside its throwaway container (compose service dian-toolbox, profile tools): the DIAN
# e-invoicing toolbox v1.9. It is downloaded once from the DIAN into backend/storage/app/dian-toolbox, which git
# ignores, checked against its SHA-256 and extracted. The repository keeps only the schemas it validates with
# (backend/resources/dian/xsd, NOTICE); this step compares them with the toolbox's own, byte for byte. make
# dian-schematron runs it first, because it reads the toolbox's schematron and code lists.
set -eu

URL=https://www.dian.gov.co/impuestos/factura-electronica/Documents/Caja-de-herramientas-FE_V19_v2026.zip
SHA256=868bc7ac184f646b47a61e2b3a978c0c195fc958e8ec7b54e95010717aa477e5
ZIP=Caja-de-herramientas-FE_V19_v2026.zip
FOLDER="Caja de herramientas FE - V1.9 - (v2026-2)"

mkdir -p /storage/dian-toolbox
cd /storage/dian-toolbox

if ! echo "$SHA256  $ZIP" | sha256sum -c -s 2>/dev/null; then
  echo "Downloading the DIAN toolbox v1.9 (26 MB) from www.dian.gov.co..."
  wget -q -O "$ZIP.part" "$URL"

  if ! echo "$SHA256  $ZIP.part" | sha256sum -c -s; then
    echo "The download does not have the SHA-256 of the toolbox v1.9 ($SHA256): it stays in backend/storage/app/dian-toolbox/$ZIP.part, and nothing is extracted." >&2
    exit 1
  fi

  mv "$ZIP.part" "$ZIP"
fi

echo "The toolbox zip has its SHA-256: $SHA256."

if [ ! -f "$FOLDER/XSL/DIAN-UBL21-model-compiled.xsl" ]; then
  unzip -q -o "$ZIP"
  echo "Extracted into backend/storage/app/dian-toolbox/$FOLDER."
fi

status=0
same=0

for schema in $(cd /xsd && find . -name '*.xsd' | sort); do
  if cmp -s "/xsd/$schema" "$FOLDER/XSD/$schema"; then
    same=$((same + 1))
  else
    echo "   DIFFERENT  backend/resources/dian/xsd/${schema#./} is not the toolbox's XSD/${schema#./}." >&2
    status=1
  fi
done

if [ "$status" -ne 0 ]; then
  exit 1
fi

echo "The $same schemas of backend/resources/dian/xsd are the toolbox's own, byte for byte."
