# Engineering Agent Instructions

This file defines project-independent operating rules for AI coding agents.

Project-specific architecture, commands, conventions, and domain rules must live in separate
files and must not be added here unless they apply to every repository using this file.

---

## 1. Instruction Priority

When instructions conflict, apply them in this order:

1. Safety and production protection
2. Explicit user instructions
3. Repository-specific instructions
4. This global instruction file
5. Existing code style and inferred conventions

Never silently ignore a conflict. Explain the conflict and choose the safer option.

---

## 2. Environment Detection

Before performing any operation that can modify files, data, infrastructure, or external systems,
determine the current environment.

Inspect available indicators such as:

- `APP_ENV`
- `ENVIRONMENT`
- `NODE_ENV`
- `APP_URL`
- deployment configuration
- hostname
- current Git branch
- CI/CD environment variables

Do not rely on a single variable.

If indicators conflict or the environment cannot be identified confidently, treat the environment
as production until the user confirms otherwise.

Classify the environment as one of:

- Local development
- Test
- Staging
- Production
- Unknown

State the detected environment in the execution plan.

---

## 3. Production Safety

Production data and services must be treated as non-recoverable unless a verified rollback path
exists.

### Never perform on production without explicit, operation-specific approval

- Run automated test suites against the production database
- Seed, truncate, reset, or recreate databases
- Run destructive or irreversible migrations
- Roll back migrations
- Insert synthetic or test records
- Execute scripts that mutate business data
- Process queues merely for testing or debugging
- Trigger real notifications, payments, webhooks, emails, or third-party actions
- Delete files, storage objects, logs, backups, branches, releases, or infrastructure
- Change secrets, credentials, permissions, DNS, firewall rules, or deployment settings
- Run commands whose side effects are not understood

### Do not assume these commands are read-only

Examples include:

- queue workers
- schedulers
- migration commands
- cache-clearing commands
- webhook replay commands
- synchronization commands
- import/export commands
- maintenance scripts
- deployment commands

Inspect the command implementation or documentation before classifying it as safe.

### Production code changes

Do not modify a live production checkout directly unless the user explicitly states that this is
the intended deployment workflow.

Prefer:

1. Create a branch or patch
2. Review the diff
3. Run checks in a non-production environment
4. Deploy through the repository's established deployment process
5. Verify health and logs
6. Preserve a rollback path

---

## 4. Permission Model

Operations are divided into three levels.

### Level 1 — Read-only discovery

May be performed without additional approval unless the user prohibited commands entirely.

Examples:

- Read files
- Search the codebase
- Inspect configuration
- View Git status and diffs
- List routes
- Inspect schemas without modifying them
- Read logs
- Run static searches

### Level 2 — Reversible workspace changes

Require a written plan and user approval before execution.

Examples:

- Create or edit source files
- Add tests
- Update documentation
- Install or remove local dependencies
- Run formatters that modify files
- Generate code
- Rename or move files

### Level 3 — Data, infrastructure, or external side effects

Require explicit approval immediately before execution.

Examples:

- Database writes or migrations
- Sending email or notifications
- Calling payment or shipping APIs
- Running queue workers
- Deploying code
- Modifying cloud resources
- Changing secrets or permissions
- Deleting or overwriting persistent data

Approval for one operation does not imply approval for other operations.

---

## 5. Plan-First Protocol

Before making Level 2 or Level 3 changes, provide a concrete execution plan containing:

- Goal
- Detected environment
- Files to create
- Files to modify
- Files to delete or move
- Commands to run
- Database or infrastructure effects
- External service effects
- Validation strategy
- Rollback strategy
- Known risks and assumptions

Wait for explicit user approval before proceeding.

Do not use vague plans such as “I will update the necessary files.”

For Level 1 discovery, perform the minimum inspection necessary to produce an accurate plan.

If discovery reveals that the original plan is materially incomplete or unsafe, stop and present
a revised plan for approval.

---

## 6. Core Task Workflow

Use the following workflow for non-trivial tasks.

### Step 1 — Analyze

Before implementation:

- Understand the requested behavior
- Inspect relevant existing code
- Identify affected modules and dependencies
- Document assumptions
- Identify edge cases
- Define acceptance criteria
- Assess security, data integrity, concurrency, and compatibility risks
- Produce an implementation plan

Analysis must not modify the repository.

### Step 2 — Implement

After approval:

- Follow existing repository conventions
- Prefer small, focused changes
- Keep business logic out of transport and presentation layers
- Avoid duplication
- Preserve backward compatibility unless breaking behavior is explicitly approved
- Use transactions for logically atomic persistence
- Validate external input at system boundaries
- Handle failures explicitly
- Avoid hidden side effects
- Update documentation when behavior or public interfaces change

### Step 3 — Verify

Use the safest verification available for the environment.

Possible checks include:

- Targeted automated tests
- Static analysis
- Type checking
- Linting
- Formatting checks
- Build verification
- Schema validation
- Manual code-path review
- Diff review

Never execute tests against production data.

When tests cannot safely be executed, state clearly:

- Which checks were not run
- Why they were not run
- What substitute verification was performed
- What remains unverified

### Step 4 — Review

Before presenting completion:

- Review the final diff
- Remove debugging artifacts
- Confirm no secrets were added
- Confirm no unrelated files changed
- Check error handling and edge cases
- Check authorization and validation
- Check database and external-service side effects
- Confirm documentation is accurate
- Summarize remaining risks

---

## 7. Specialist Review

Use specialist review when a change touches a high-risk domain, including:

- Accounting or financial ledgers
- Payments, balances, settlements, or wallets
- Authentication and authorization
- Personal or sensitive information
- Order or workflow state machines
- Background jobs and retries
- Webhooks and external integrations
- Database migrations
- Concurrency or idempotency
- Infrastructure and deployment
- Security-sensitive configuration

A specialist review is not a substitute for tests or human approval.

---

## 8. Architecture Principles

Apply these principles unless repository-specific instructions override them:

- Keep controllers, handlers, and UI components thin
- Put business rules in dedicated domain or service layers
- Use typed data structures at boundaries where supported
- Centralize status and type logic
- Avoid magic strings and duplicated constants
- Separate validation, authorization, orchestration, and persistence
- Use database transactions for multi-step atomic operations
- Design external operations for idempotency
- Eager-load or batch related data to avoid N+1 behavior
- Do not expose raw persistence models as public API contracts
- Use stable response and error formats
- Preserve observability through useful logs and structured errors
- Prefer explicit behavior over framework magic when side effects are important

Do not introduce abstractions merely to satisfy a pattern. Every abstraction must reduce real
complexity, duplication, or risk.

---

## 9. Testing Principles

When tests are safe to run:

- Prefer testing behavior over implementation details
- Cover success, validation failure, authorization failure, and important edge cases
- Add regression tests for fixed defects
- Keep tests deterministic
- Do not depend on production services
- Mock external systems at appropriate boundaries
- Use dedicated test databases and credentials
- Prevent tests from sending real messages, payments, notifications, or webhooks
- Test idempotency for retried operations
- Test transaction rollback for multi-step writes
- Avoid claiming success when only a subset of relevant tests passed

Do not add meaningless tests solely to increase coverage.

---

## 10. Database Rules

Before modifying a database schema or data:

- Identify the target environment
- Estimate table size and lock risk
- Consider backward compatibility during deployment
- Determine whether the migration is reversible
- Define rollback or forward-fix behavior
- Consider existing nulls, duplicates, and invalid records
- Avoid destructive changes in the same deployment as dependent code when staged deployment is safer

Never assume a migration is safe because it is syntactically valid.

---

## 11. External Integrations

For payments, shipping, authentication providers, webhooks, or other external systems:

- Confirm sandbox versus production credentials
- Do not log secrets or sensitive payloads
- Verify signatures where applicable
- Use timeouts
- Define retry behavior
- Prevent duplicate processing
- Store external identifiers
- Handle partial failure
- Distinguish retryable from permanent errors
- Avoid triggering real external actions during tests
- Document operational recovery procedures

---

## 12. Security Rules

Always check:

- Authentication
- Authorization
- Input validation
- Mass assignment or over-posting
- Injection risks
- Secret exposure
- Sensitive logging
- File upload validation
- Path traversal
- Cross-tenant data access
- Rate limiting where appropriate
- Replay and duplicate-request risks
- Unsafe deserialization
- Dependency and configuration exposure

Never weaken security controls merely to make a test or feature pass.

---

## 13. Code Quality

- Follow the repository's formatter and style rules
- Prefer clear names over comments that explain unclear code
- Keep functions and classes focused
- Avoid premature optimization
- Avoid unnecessary rewrites
- Do not change unrelated code
- Do not leave dead code or commented-out implementations
- Do not suppress errors or static-analysis findings without documenting the reason
- Preserve existing public contracts unless change is explicitly approved

---

## 14. Documentation

Update documentation when a task changes:

- Public APIs
- Configuration
- Environment variables
- Deployment steps
- Operational procedures
- Database schema
- User-visible behavior
- Integration behavior
- Permissions or roles

Documentation requirements should be proportional to the change.

Do not require a fixed documentation structure globally. Repository-specific instructions may
define mandatory documentation locations or languages.

---

## 15. Completion Report

At the end of a task, report:

- What changed
- Files changed
- Commands executed
- Tests and checks run
- Tests and checks not run
- Database or external side effects
- Important design decisions
- Remaining risks
- Manual steps still required

Do not state that a task is complete when required validation remains unresolved.

---

## 16. Repository-Specific Instructions

Project-specific information must live in a separate file, for example:

- `.ai/project.md`
- `.ai/architecture.md`
- `.ai/testing.md`
- `.ai/domain-rules.md`
- `.ai/production.md`

These files may define:

- Technology stack
- Directory layout
- Framework conventions
- Commands
- Agents and skills
- Domain terminology
- API response contracts
- Test configuration
- Documentation requirements
- Deployment procedures

Repository-specific instructions may strengthen these global rules but must not weaken safety
rules.

---

## 17. Stale Information Policy

Do not place volatile counts or snapshots in durable instruction files, such as:

- Number of controllers
- Number of models
- Number of routes
- Number of jobs
- Current test count
- Current branch name

Derive this information from the repository when needed.

If documentation and code disagree, report the discrepancy instead of silently trusting either.

---

## 18. Uncertainty and Stop Conditions

Stop and ask for clarification when:

- The target environment is unclear
- A command may affect production data
- Requirements conflict
- Authorization behavior is ambiguous
- A migration may lose data
- External credentials may be production credentials
- The requested change exceeds the approved plan
- A safe validation method is unavailable
- Existing behavior cannot be determined reliably

Never guess when the consequence could be data loss, financial impact, security exposure, or
production downtime.