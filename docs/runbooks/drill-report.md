# Drill report: the DIAN goes down

A blameless review of one run of `make cloud-drill`, the emulated cloud's incident drill (scripts/cloud-drill.sh). It ran on the night of 2026-11-20, from 04:22 to 04:26 UTC on 2026-11-21.

It was a drill:

- The DIAN was the local simulator, made to fail on purpose with one of its failure modes.
- The company, its customer and its invoices were synthetic, created for the run and deleted after it.
- **Every time below is an emulator figure** (Floci 2.5.0 on this machine), not an AWS one.

The drill rehearses the response in [dead-letters.md](dead-letters.md) and [dian-errors.md](dian-errors.md) end to end, with their own commands. It also measures the time to detect, to notify and to recover.

## What happened

First, the baseline. A company issued three invoices. The DIAN accepted them, and they were delivered to the company's customer by email, with their webhooks. Both dead-letter queues were empty, and the `chain-dead-letters` alarm was not in `ALARM`.

Then the DIAN went down: the simulator responded with HTTP 500 to every call from every company. The company kept issuing: five invoices through the edge with an API key, as an integration issues them. The API numbered each one, gave it its CUFE and responded at once, with its `X-Correlation-Id`. The chain generated and signed their XML and tried to transmit them.

Each transmission failed, retried twice and gave up. The local stage retries after 2 and 5 s, which the emulator stretches to about a minute each. Each document was left failed at `transmit`, and the company saw it that way in the web app and in the `invoice.failed` webhook. A copy of each transmission's message went to the chain's dead-letter queue, with its reason and its invoice's correlation id.

The emulator evaluated the `chain-dead-letters` alarm on that queue's depth and turned it to `ALARM`. The notifier posted it to the chat, with its runbook.

The operator followed [dead-letters.md](dead-letters.md):

1. Checked every alarm (`make cloud-alarms`).
2. Read the dead letters without consuming them (`make cloud-dead-letters QUEUE=chain`). There were five transmissions at attempt 3 of 3, with HTTP 500, each with its company, document and journey.
3. Followed one invoice's journey with the saved query (`make cloud-follow`). It showed the request, the XML, the signature, three failed `dian.call` lines, `queue.dead_letter` and the failure webhook.
4. Read the chain (`make cloud-artisan ARGS="chain:status"`). Five documents were failed at `transmit`. Of the 18 calls to the DIAN in the last 15 minutes, 15 got no response; the other three were the baseline's.

The probe from [dian-errors.md](dian-errors.md) said the DIAN was down (`dian:probe` exits 1). The drill then restored the DIAN, as the real one would come back by itself. The probe confirmed it. The operator moved the dead letters back with the native SQS redrive (`make cloud-redrive QUEUE=chain`, a message move task). Each transmission started a new run and was accepted, and its invoice was delivered.

Followed again after the recovery with the same correlation id, that invoice's journey was one story of 50 lines in the api, chain and webhooks functions. It held the request, the three failed calls and the dead letter. Then came the step that resumed from it (`chain.resumed`), the call that got through, the acceptance, the email and the four webhooks.

## Timeline

| UTC | From the start | What |
| --- | --- | --- |
| 04:22:13 | +0 s | The DIAN goes down. The five incident invoices are issued through the edge, and each gets an immediate response with its `X-Correlation-Id` |
| 04:22:16 | +3 s | The first call to the DIAN without a response; the transmissions retry |
| 04:24:20 | +127 s | Every incident transmission has given up: five messages in the chain's dead-letter queue |
| 04:25:01 | +168 s | The emulator turns `chain-dead-letters` to `ALARM` at its next evaluation of the queue's depth: **detected** |
| 04:25:01 | +168 s | Its notification reaches the chat, naming `docs/runbooks/dead-letters.md`: **notified** |
| 04:25:07 | +174 s | The DIAN responds again; `dian:probe` confirms it |
| 04:25:08 | +175 s | The dead letters are moved back to the chain queue |
| 04:25:13 | +180 s | The last incident invoice is accepted and delivered: **recovered** |
| 04:26:01 | +228 s | `chain-dead-letters` is back to `OK`, and the chat hears it too |

From the start of the incident:

- **Time to detect**: 168 s.
- **Time to notify**: 168 s.
- **Time to recover**: 180 s (the alarm was back to `OK` at 228 s).

Of the 168 s, 124 went to the three attempts of each transmission, which the emulator stretches to about a minute per retry. Another 41 went to waiting for the alarm's next evaluation, once a minute in the emulator. Once the alarm was in the chat, the response took 12 s. The whole drill took 245 s, including the baseline and the checks.

## Impact

- Five invoices from one company waited 180 s for their validation and delivery. None was lost, accepted twice or delivered twice, and the numbering had no gap (1 to 8).
- For about 48 s, each of them showed to the company as failed at `transmit`. The company could have pressed Retry now: either way back recovers an invoice once ([dead-letters.md](dead-letters.md), step 5). The customer got each invoice once, after the recovery.
- Against the service objective: **Time to the DIAN response** does not count the minutes the DIAN itself is down.
- Nothing else on the platform was affected: the API responded to every request, and the webhooks were delivered.

## What worked

- Detection needs nothing but the queues. A transmission that gives up puts its message in the dead-letter queue at once, and the emulator evaluates the alarm on that queue's depth for real. So the outage was detected and notified in the emulator, with the runbook in the message.
- The dead letters tell the story without anyone opening a message body: type, company, document, journey, attempt and reason.
- One correlation id, from the API's response, followed the invoice through every function, from its request to its last webhook. The dead letter kept the id, and the step that resumed from it went on under it.
- The native redrive needs no code of our own, and each step that gave up starts again exactly once. A message from a run already retried does nothing, and a Retry now after the redrive is refused.
- Exactly once: one acceptance, one email and one delivery attempt per invoice, and one webhook per event, 29 in all, each delivered once.

## What to improve

- **The DIAN's own alarm.** `dian-error-rate` uses metric math, which the emulator never evaluates. So the outage was detected only when the transmissions gave up, nearly three minutes later. In AWS, `dian-error-rate` fires after ten minutes of errors, and the dead letters after three. An alarm on `DianErrors` alone, a single metric the emulator evaluates, would be worth having for an outage that is still young.
- **What the company sees.** Once their transmissions gave up, the invoices showed as failed until the redrive, though the platform would retry them. In a long outage, that is hours. A state that says "waiting for the DIAN" would tell the truth. That is a product change, not an infrastructure one.
- **The local stage's retries** (2 and 5 s) keep the drill short. In prod there are five retries over about three minutes, then the dead letters. Longer outages need an operator each time; an automatic redrive once `dian:probe` passes is a candidate.
- **The drill itself**, which this run found a gap in. Earlier runs deleted the temporary company as soon as the invoices were delivered, before the incident invoices' `invoice.delivered` webhooks went out. They counted only the webhooks that existed: 24 of 24, where 29 were owed. Following the journey to its last webhook showed the gap. The fix: the drill waits for every webhook an invoice is owed before it deletes the company, and checks one webhook of each event per invoice.
- **The emulator** gives a scheduled retry back after its queue's 60 s visibility timeout, whatever the retry's delay. So the retry times are not those AWS would show (docs/limitations.md).
