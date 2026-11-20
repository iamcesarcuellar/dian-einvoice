// Entry point of the CDK app (cdk.json): both stages. make cloud-up deploys local/* to the local AWS
// emulator; prod/* is synthesized and validated only, never deployed.
import { buildApp } from '../lib/application.ts'

buildApp().app.synth()
