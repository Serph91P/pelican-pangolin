# Pangolin API key and Org ID

Use an **organization-scoped Integration API key**, not a root key. Find the Org ID in the organisation's **General Settings** information card or the first slug after the dashboard host in that organisation's URL. [Official Org ID help](https://docs.pangolin.net/manage/organizations/org-id).

The minimal raw-publication actions (checked against Pangolin 1.25.3) are:

- `listSites`
- `listResources`
- `getResource`
- `createResource`
- `updateResource`
- `deleteResource`
- `listTargets`
- `createTarget`
- `updateTarget`

Add **`listOrgDomains` only for explicitly used HTTP/HTTPS publication**. Raw-only settings save/preflight do not call domain inventory. HTTP mode/domain selection performs the relevant domain reads. See [official Integration API help](https://docs.pangolin.net/manage/integration-api/using-the-integration-api).

The plugin does not need organization administration, user, role, policy, private resource, DNS provider, blueprint, site mutation or root permissions. There is no automatic root discovery or token parsing.

Settings save checks `listSites` and `listResources`, plus `listOrgDomains` only when an HTTP default domain is supplied. Node mapping selection/read validation checks `listSites`. Preflight reports which reads succeeded. These checks **do not prove mutation permission**; no probe resources are created, and write permissions are exercised only by approved reconciliation. They also do not prove infrastructure application or external reachability.

The public game host is where players connect. A site's private reachable allocation IP (or the explicit wildcard target in a node mapping) is where Pangolin forwards traffic. Neither is derived from the API URL.

Store the token only through Pelican's plugin settings. The token field stays blank; leaving it blank preserves the current value. Never place a production token in logs, support output, fixtures, screenshots, or source control. Rotate a token immediately if exposed.
