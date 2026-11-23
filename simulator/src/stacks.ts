// One simulator serves the local stack and the emulated cloud, and keeps each one's records apart: taxpayers,
// processed documents, failure modes, the activity log and the webhook receiver. The local stack uses the root
// paths and the emulated cloud the same paths under /cloud, so a number one of them sent never makes the other's
// next document a repetition (rule 90), and a failure mode set for one never reaches the other. Each stack keeps its
// records in a state file of its own: state.json for the local stack and cloud/state.json for the emulated cloud.
import { join } from 'node:path'
import { Store } from './state.ts'
import type { State } from './state.ts'

export const STACKS = ['local', 'cloud'] as const

export type StackName = (typeof STACKS)[number]

/** Where the paths of each stack start. */
export const PREFIXES: Record<StackName, string> = { local: '', cloud: '/cloud' }

export interface Stack {
  readonly name: StackName
  readonly store: Store
  readonly state: State
}

/** The records of every stack, from their state files under the data directory. */
export function openStacks(directory: string): Record<StackName, Stack> {
  const open = (name: StackName, folder: string): Stack => {
    const store = new Store(folder)

    return { name, store, state: store.state }
  }

  return { local: open('local', directory), cloud: open('cloud', join(directory, 'cloud')) }
}

/** The stack a request path belongs to, and the path inside it: /cloud/... is the emulated cloud's, any other the local stack's. */
export function stackOf(pathname: string): { name: StackName, path: string } {
  const match = /^\/cloud(\/.*)?$/i.exec(pathname)

  return match === null ? { name: 'local', path: pathname } : { name: 'cloud', path: match[1] ?? '/' }
}
