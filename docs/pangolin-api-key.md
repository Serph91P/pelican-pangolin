# Pangolin API Key

Use an organization-scoped Integration API key. Do not use a root key. Version 1 needs only these Pangolin actions:

- `listSites`

- `listOrgDomains`
- `listResources`
- `getResource`
- `createResource`
- `updateResource`
- `deleteResource`
- `listTargets`
- `createTarget`
- `updateTarget`

The plugin does not need organization administration, user, role, policy, private resource, DNS provider, blueprint, or site mutation permissions.

Store the token only through Pelican's plugin settings. Never place a production token in logs, support output, fixtures, screenshots, or source control. Rotate a token immediately if it is exposed.
