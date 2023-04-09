# glued-stor
Content aware storage microservice.

Current maximum file size is capped at 8000M and 1800s execution time per nginx/php settings in
- glued/Config/Nginx/sites-enabled/glued-stor
- glued/Config/Nginx/snippets/locations/glued-stor.conf
- glued/Config/Php/99-glued-stor.ini

Nginx is applied automatically.
- TODO: make max upload filesize configurable.

## Testing

```bash
curl -k -XPOST https://glued/api/stor/files/v1 -F file=@/home/user/somefile.pdf
```

## Example configuration

Under the dgs (devicegroups) and devices keys, unique item keys must be used. to avoid mishaps.
```yaml
---
stor:
  dgs:
    eb74303a-c062-11ed-9fc5-3b799a728aee:
      label:      'local'
      comment:    'default devices group'
      default:    true
    03f5e327-d4cc-48c9-8e00-2f4617cea3c7:
      label:      'networked'
    5fc4f942-9c74-46e0-b375-f552f58fb158:
      label:      'remotebak'
  devices:
    # DEFAULTS
    09e0ef57-86e6-4376-b466-a7d2af31474e:
      label:      'datadir'
      comment:    'default/fallback filesystem storage (path is resolved automatically)'
      enabled:    true
      adapter:    'filesystem'
      version:    'latest'
      path:       ~
      dg:         eb74303a-c062-11ed-9fc5-3b799a728aee
    86159ff5-f513-4852-bb3a-7f4657a35301:
      label:      'tmpdir'
      comment:    'temporary storage (path is resolved automatically)'
      enabled:    true
      adapter:    'filesystem'
      version:    'latest'
      dg:         ~
      path:       ~
    # APPENDED
    15cd6871-c139-4683-a4e4-7bf07fa0a7c1:
      label:      'backup'
      comment:    'a slow backup storage'
      enabled:    true
      adapter:    filesystem
      version:    latest
      path:       '/opt/backup'
      type:       eventual
      dg:         eb74303a-c062-11ed-9fc5-3b799a728aee
    5a7c82ae-28f5-46e8-9a61-9c952ae3a9f8:
      label: Minio
      comment: The local minio stuff
      enabled: false
      adapter: s3
      version: latest
      endpoint: http://localhost:9000
      region: us-east-1
      bucket: some-bucket
      access-key: access-key
      secret-key: secret-key
      path-style: true
      dg: 03f5e327-d4cc-48c9-8e00-2f4617cea3c7
    a5bfa82d-ac79-4c4a-ae18-62c9f23685eb:
      label: Nas
      comment: NAS on NFS
      enabled: false
      adapter: filesystem
      version: latest
      path: /mnt/nas
      dg: 03f5e327-d4cc-48c9-8e00-2f4617cea3c7
    a64027ed-d53d-4846-a47f-56b847f596b3:
      label: Btrfs send over ssh (backup)
      comment: Remote btrfs on nas for snapshot send backups over ssh
      enabled: false
      adapter: btrfs-send
      filesystem: btrfs
      version: latest
      ssh: user@remote -P 2022
      dg: 5fc4f942-9c74-46e0-b375-f552f58fb158
```