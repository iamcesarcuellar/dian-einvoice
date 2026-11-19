# Test fixtures

Synthetic data only. No real company or person appears here, and the DIAN never received any of it.

- `invoice.xml`, `credit-note.xml`, `debit-note.xml`: invoice SETP990000000, credit note NC1 and debit
  note ND1 of Demo Comercial SAS (NIT 990000001), the synthetic demo company, issued and signed by the
  backend (`make demo`) and copied unchanged from `backend/storage/app/documents`. Their customers are
  synthetic companies of the demo seeder. They are signed with the synthetic self-signed test certificate
  of the company, valid from 2026-05-03 to 2028-05-03; the tests fix their clock inside that validity.
  Do not edit these files: any change breaks the signature.
- `taxpayer.json`: the issuer as the backend registered it in the simulator, with the software ID, the
  PIN and the numbering range with its technical key, all test values. It comes from the simulator
  state: the admin API does not return the PIN or the technical key.
