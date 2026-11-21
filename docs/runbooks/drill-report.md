# Drill report: the DIAN goes down

A blameless review of one run of `make cloud-drill`, the incident drill of the emulated cloud (scripts/cloud-drill.sh), on the night of 2026-11-20 (04:22 to 04:26 UTC of 2026-11-21). It is a drill: the DIAN is the local simulator, made to fail on purpose with its failure control; the company, its customer and its invoices are synthetic, created for the run and deleted after it; and **every time below is a figure of the emulator** (Floci 2.5.0 on this machine), not of AWS. Its purpose is to rehearse the response of [dead-letters.md](dead-letters.md) and [dian-errors.md](dian-errors.md) end to end, with their own commands, and to measure the time to detect, to notify and to recover.

## What happened

A company issued three invoices, accepted by the DIAN and delivered to its customer by email, with their webhooks: the baseline, with both dead-letter queues empty and the alarm `chain-dead-letters` out of `ALARM`. Then the DIAN went down: the simulator answered HTTP 500 to every call of every company. The company kept issuing: five invoices through the edge with an API key, as an integration issues them. The API numbered, signed and answered each one at once, with its `X-Correlation-Id`; the chain generated and signed their XML and tried to transmit them. Each transmission failed, retried twice (the stage local retries after 2 and 5 s, which the emulator stretches to about a minute each), and gave up: each document was left failed at `transmit`, its customer saw it so in the web app and in the webhook `invoice.failed`, and a copy of the message of each transmission went to the dead-letter queue of the chain, with its reason and the correlation id of its invoice.

The emulator evaluated the alarm `chain-dead-letters` on the depth of that queue and turned it to `ALARM`; the notifier posted it to the chat, with its runbook. The operator, following [dead-letters.md](dead-letters.md), looked at every alarm (`make cloud-alarms`), at the dead letters without consuming them (`make cloud-dead-letters QUEUE=chain`: five transmissions, attempt 3 of 3, HTTP 500, each with its company, document and journey), followed the journey of one invoice with the saved query (`make cloud-follow`: the request, the XML, the signature, three calls `dian.call` that failed, `queue.dead_letter` and the webhook of the failure), and read the chain (`make cloud-artisan ARGS="chain:status"`: five failed at `transmit`; 15 of the 18 calls to the DIAN of the last 15 minutes without an answer, the other three those of the baseline). The probe of [dian-errors.md](dian-errors.md) said the DIAN was down (`dian:probe` exits 1). The drill then restored the DIAN, as it would come back by itself; the probe confirmed it, and the operator moved the dead letters back with the native redrive of SQS (`make cloud-redrive QUEUE=chain`, a message move task). Each transmission started a new run, was accepted, and its invoice was delivered.

Followed again after the recovery with the same correlation id, the journey of that invoice was one story of 50 lines in the functions api, chain and webhooks: its request, the three calls that failed, its dead letter, the step that came back from it (`chain.resumed`), the call that got through, its acceptance, its email and its four webhooks.

## Timeline

| UTC | From the start | What |
| --- | --- | --- |
| 04:22:13 | +0 s | The DIAN goes down; the five invoices of the incident are issued through the edge, each answered at once with its `X-Correlation-Id` |
| 04:22:16 | +3 s | The first call to the DIAN without an answer; the transmissions retry |
| 04:24:20 | +127 s | Every transmission of the incident gave up: five messages in the dead-letter queue of the chain |
| 04:25:01 | +168 s | The emulator turns `chain-dead-letters` to `ALARM`, at its next evaluation of the depth of the queue: **detected** |
| 04:25:01 | +168 s | Its notification reaches the chat, naming `docs/runbooks/dead-letters.md`: **notified** |
| 04:25:07 | +174 s | The DIAN answers again; `dian:probe` confirms it |
| 04:25:08 | +175 s | The dead letters are moved back to the chain queue |
| 04:25:13 | +180 s | The last invoice of the incident accepted and delivered: **recovered** |
| 04:26:01 | +228 s | `chain-dead-letters` back to `OK`, which the chat hears too |

**Time to detect** 168 s, **to notify** 168 s, **to recover** 180 s from the start of the incident (the alarm back to `OK` at 228 s). Of the 168 s, 124 are the three attempts of each transmission, stretched by the emulator to about a minute per retry, and 41 the wait for the next evaluation of the alarm, once a minute in the emulator; the response, once the alarm was in the chat, took 12 s. The whole drill, baseline and checks included, took 245 s.

## Impact

- Five invoices of one company waited 180 s for their validation and their delivery; none was lost, accepted twice or delivered twice, and the numbering kept no gap (1 to 8).
- For about 48 s each of them showed as failed at `transmit` to the company, which could have pressed Retry now: either way back recovers it once ([dead-letters.md](dead-letters.md), step 5). Their customers got their invoices once, after the recovery.
- Against the objective of service: the time to the answer of the DIAN does not count the minutes the DIAN itself is down.
- Nothing else of the platform was affected: the API answered every request, and the webhooks were delivered.

## What worked

- The detection needs nothing but the queues: a transmission that gives up puts its message in the dead-letter queue at once, and the alarm on its depth is one the emulator evaluates for real, so the outage was detected and notified in the emulator, with the runbook in the message.
- The dead letters tell the story without opening a body: type, company, document, journey, attempt and reason.
- One correlation id, from the answer of the API, followed the invoice through every function from its request to its last webhook: the dead letter keeps it, and the step that came back from it went on under it.
- The native redrive needs no code of our own, and each step that gave up starts again exactly once: a message of a run already retried does nothing, and a Retry now after the redrive is refused.
- Exactly once: one acceptance, one email and one delivery attempt per invoice, and one webhook of each event, 29 in all, each delivered once.

## What to improve

- **The alarm of the DIAN itself.** `dian-error-rate`, written with metric math, is never evaluated in the emulator, so the outage was detected only when the transmissions gave up, nearly three minutes later. In AWS it fires after ten minutes of errors; the dead letters, after three. Worth an alarm on `DianErrors` alone, a single metric the emulator evaluates, for an outage that is still young.
- **What the customer sees.** Once their transmissions gave up, the invoices showed as failed until the redrive, though the platform would retry them; in a long outage that is hours. A state that says "waiting for the DIAN" would tell the truth: a change of the product, not of the infrastructure.
- **The retries of the stage local** (2 and 5 s) keep the drill short; in prod five retries over about three minutes, then the dead letters. Outages longer than that need an operator each time: an automatic redrive once `dian:probe` answers is a candidate.
- **The drill itself**, found by this run. Its earlier runs deleted the temporary company as soon as the invoices were delivered, before the webhook `invoice.delivered` of the invoices of the incident went out, and counted only the webhooks that existed: 24 of 24, where 29 were owed. Following the journey to its last webhook showed the gap. The drill now waits for every webhook an invoice is owed before it deletes the company, and checks one of each event per invoice.
- **The emulator** gives a scheduled retry back after the 60 s visibility timeout of its queue whatever its delay, so the times of the retries are not those AWS would show (docs/limitations.md).
