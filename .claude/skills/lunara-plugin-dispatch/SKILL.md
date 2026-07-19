```markdown
# lunara-plugin-dispatch Development Patterns

> Auto-generated skill from repository analysis

## Overview
This skill teaches the core development patterns and conventions used in the `lunara-plugin-dispatch` TypeScript repository. You'll learn how to structure files, write imports and exports, and follow the established testing patterns. This guide is ideal for contributors aiming for consistency and maintainability in this codebase.

## Coding Conventions

### File Naming
- Use **kebab-case** for all filenames.
  - Example: `plugin-dispatcher.ts`, `event-handler.test.ts`

### Import Style
- Use **relative imports** for all module references.
  - Example:
    ```typescript
    import { dispatchEvent } from './event-dispatcher';
    ```

### Export Style
- Use **named exports** exclusively.
  - Example:
    ```typescript
    // In event-dispatcher.ts
    export function dispatchEvent(event: Event) { ... }
    ```

### Commit Patterns
- Commit messages are **freeform** (no strict prefixes).
- Average commit message length: ~44 characters.

## Workflows

### Adding a New Feature
**Trigger:** When implementing a new feature or module.
**Command:** `/add-feature`

1. Create a new file using kebab-case (e.g., `new-feature.ts`).
2. Write your code using named exports.
3. Import dependencies using relative paths.
4. Add or update tests in a corresponding `*.test.ts` file.
5. Commit your changes with a clear, concise message.

### Writing Tests
**Trigger:** When adding or updating functionality.
**Command:** `/write-test`

1. Create or update a test file with the `.test.ts` suffix (e.g., `event-handler.test.ts`).
2. Write tests for each exported function or module.
3. Use the project's preferred (undetected) testing framework.
4. Run tests to ensure correctness.

### Refactoring Code
**Trigger:** When improving or restructuring existing code.
**Command:** `/refactor`

1. Identify code to refactor.
2. Maintain kebab-case file naming and relative imports.
3. Update named exports as needed.
4. Update or add tests to reflect changes.
5. Commit with a message describing the refactor.

## Testing Patterns

- Test files are named with the `.test.ts` suffix and placed alongside or near the code they test.
  - Example: `event-dispatcher.test.ts`
- Each test file should cover the exported functions from its corresponding module.
- The specific testing framework is not detected; follow existing patterns or consult the team for the preferred tool.

## Commands
| Command       | Purpose                                      |
|---------------|----------------------------------------------|
| /add-feature  | Scaffold and implement a new feature/module  |
| /write-test   | Create or update a test file                 |
| /refactor     | Refactor existing code                       |
```
