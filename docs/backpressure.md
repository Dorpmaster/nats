# Backpressure / Slow Consumer v1

## Current scope

Backpressure v1 is implemented at message-dispatcher level with per-subscription pending message limits.

## Controls

- `maxPendingMessagesPerSubscription` (default: `1000`)
- `slowConsumerPolicy`:
  - `ERROR` (default): throw `SlowConsumerException`
  - `DROP_NEW`: drop overflow messages

## Behavior

- pending counters are incremented before handler invocation,
- counters are decremented in `finally`, even when handler throws,
- on overflow:
  - `ERROR` stops normal processing with an explicit exception,
  - `DROP_NEW` drops only the new message and keeps dispatcher alive.

## Notes

- This is v1 minimal protection.
- Byte-based limits and write-buffer limits are not yet implemented.
