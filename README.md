# glued-stor
Content aware storage microservice.

Current maximum file size is capped at 8000M and 1800s execution time per nginx/php settings in
- glued/Config/Nginx/sites-enabled/glued-stor
- glued/Config/Nginx/snippets/locations/glued-stor.conf
- glued/Config/Php/99-glued-stor.ini

Nginx is applied automatically.
- TODO: make max upload filesize configurable.

## Example configuration

```yaml
stor:
    devices:
      - name:       'Minio'
        online:     false
        adapter:    's3'
        version:    'latest'
        endpoint:   'http://localhost:9000'
        region:     'us-east-1'
        bucket:     'some-bucket'
        access-key: 'access-key'
        secret-key: 'secret-key'
        path-style: true
      - name:       'btrfs-1'
        dscr:       'local btrfs raid 1'
        online:     false
        adapter:    'filesystem'
        filesystem: 'btrfs'
        version:    'latest'
        path:       ${glued.datapath}
      - name:       'btrfs-2'
        dscr:       'local btrfs backup drive'
        online:     false
        adapter:    'filesystem'
        filesystem: 'btrfs'
        version:    'latest'
        path:       '/opt/backups'
      - name:       'NAS'
        dscr:       'NAS on NFS'
        online:     false
        adapter:    'filesystem'
        filesystem: 'nfs'
        version:    'latest'
        path:       '/mnt/nas'
      - name:       'Backup'
        dscr:       'Remote btrfs on nas for snapshot send backups over ssh'
        online:     false
        adapter:    'btrfs-send'
        filesystem: 'btrfs'
        version:    'latest'
        ssh:        'user@remote -P 2022'
```