# glued-stor

Stor is a content addressable storage microservice.

## Setup

- Configure stor devices and dgs and the notification system by adding `stor.yaml` and `notify.yaml` to `/var/www/html/data/glued-stor/config`
- Create first bucket

```shell
curl 'https://glued/api/stor/v1/buckets' --compressed -X POST -H 'User-Agent: Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0' -H 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8' -H 'Accept-Language: en-US,en;q=0.5' -H 'Accept-Encoding: gzip, deflate, br' -H 'DNT: 1' -H 'Connection: keep-alive' -H 'Upgrade-Insecure-Requests: 1' -H 'Sec-Fetch-Dest: document' -H 'Sec-Fetch-Mode: navigate' -H 'Sec-Fetch-Site: cross-site' -H 'Content-Type: application/json' -H 'Origin: null' -H 'Pragma: no-cache' -H 'Cache-Control: no-cache' --data-raw '{"name": "Industra bucket", "dgs": ["66bf39ed-3913-4985-a775-ef3c87cfaee4"] }'
```

```shell
curl -k -X POST https://glued.industra.space/api/stor/v1/buckets/85d6a1f5-fed1-41b4-b4f3-3e3b206ea21f/objects     -H 'Authorization: Bearer gtk_35MCHFgkNh1PymQEOLStzMtESdo4DZXykoYWvjX9QcQ='     -H 'Content-Type: multipart/form-data'     -F 'links={ "Ryanair.pdf": { "uuid": "77384ad2-80a4-11ee-9edc-9747c96a1231", "parent": "bd4a3c4a-80a6-11ee-8e34-67217a8a9f66", "app": { "name": "client-name", "instance": "https://glued.example.com", "discover": "/api/client-name/v1/endpoint/someID" }}, "eastern loves_INDUSTRA.png": {}};type=application/json'     -F 'file[]=@/home/killua/Ryanair.pdf'     -F 'file[]=@/home/killua/eastern loves_INDUSTRA.png'     -F 'file[]=@/home/killua/todo-petru'      -F 'field1=fiels2' | jq .
```
TODO: token

## Concepts

## Using from the command line

```bash
#new
curl -k -X POST https://glued/api/stor/v1/buckets/1fcb4d5c-5364-4cf3-b24e-070c4c71f8d2/objects     -H 'Authorization: Bearer gtk_35MCHFgkNh1PymQEOLStzMtESdo4DZXykoYWvjX9QcQ='     -H 'Content-Type: multipart/form-data'     -F 'refs={"Ryanair.pdf":{"refs":{"predecessor":"77384ad2-80a4-11ee-9edc-9747c96a1231","sekatko:vpd":"bd4a3c4a-80a6-11ee-8e34-67217a8a9f66"},"meta":{"sekato":{"ocr":"sometext"},"otherapp":{"whatever":"https://glued.example.com"}}}};type=application/json'     -F 'file[]=@/home/killua/Ryanair.pdf'     -F 'file[]=@/home/killua/eastern loves_INDUSTRA.png'     -F 'file[]=@/home/killua/todo-petru'      -F 'field1=fiels2' | jq .


 {
    "Ryanair.pdf": {
        "refs": {
            "predecessor": "77384ad2-80a4-11ee-9edc-9747c96a1231",
            "sekatko:vpd": "bd4a3c4a-80a6-11ee-8e34-67217a8a9f66"
        },
        "meta": {
            "sekato": {
                "ocr": "sometext"
            },
            "otherapp": {
                "whatever": "https://glued.example.com"
            }
        }
    }
}

```

```bash
curl -k -X POST https://glued/api/stor/files/v1 \
    -H 'Authorization: Bearer gtk_35MCHFgkNh1PymQEOLStzMtESdo4DZXykoYWvjX9QcQ=' \
    -H 'Content-Type: multipart/form-data' \
    -F 'links={ "Ryanair.pdf": { "uuid": "77384ad2-80a4-11ee-9edc-9747c96a1231", "parent": "bd4a3c4a-80a6-11ee-8e34-67217a8a9f66", "app": { "name": "client-name", "instance": "https://glued.example.com", "discover": "/api/client-name/v1/endpoint/someID" }}, "eastern loves_INDUSTRA.png": {}};type=application/json' \
    -F 'file[]=@/home/killua/Ryanair.pdf' \
    -F 'file[]=@/home/killua/eastern loves_INDUSTRA.png' \
    -F 'file[]=@/home/killua/todo-petru'  \
    -F 'field1=fiels2'
```



## Metadata
- each file identified by uuid
- every uuid has a filehash associated
- unique constraint on uuid / filehash
- each app/service using the same underlying data file must use a "symlink uuid" to the data uuid.
- each symlink is associated with a natural (human readable filename) + inhereted access rights (from the app/service and the collection/object its associated to)
- file changes/revisions are symlink bound by default (content addressable storage mode)
- sharing a symlink will keep shared symlink changes in sync
- stor will allow to display revision history over a symlink
- each symlink will be associated with tags
- getting by symling is the default way
- app name, collection, object, tags, human readable filename will act as filter on symlinks. i.e.
  https://glued/api/stor/l/tag1/collection/appname/tag2 will list symlinks corresponding to the components in AND mode. wildcards could work too ig.
- tags will have context:
  - app/collection relevant
  + app/collection relevant valid only within an auth domain uuid
  + app/collection relevant valid only within an user domain uuid

Takzeee

- create endpoint bude zrat
  - [REQ] 1..n files
  - [REQ] target.service
  - [REQ] target.collection
  - [OPT] target.object
    (např. "target": { "service": "fare", "collection": "packlists", "object": "uuid nejakeho packlistu" })
  - [OPT] jwt token nebo apikey (autorizace, prirazeni created.by nejakemu user) - write prava budes mit jako anonym asi jen velmi omezena
  - [OPT] domain uuid (pokud neni zrejma z target)
  - [OPT] file meta - muzes ke kazdemu nazvu priradit uuid softlinku, pokud nebude pouzito, nebude stor nove generovat, ale pouzije tvoje

Vrati ti:

ke kazdemu souboru nazev, hash, velikost, mime, target, data uuid, symlink uuid


## Previous docs

files:
  - uuid
  - name
  - hash
  - meta.mime
  - meta.size
  - ...

files_meta
 - file_uuid
 - 

links:
- object1.uuid
- object2.uuid
- link.type: (ukládat oba směry?)
  - "predecessor":
  - "successor"
  - "parent"
  - "child"

- tags:
  - object
  - context type (app, user, domain ...)
  - context uuid (app, user, )
  - value.uuid
  - value.text

annontator will get manually by user u1 upload a contract - file 105 in version 1 (f105). annotator will tell stor to use the 
"industra" domain authorization context. stor will save it as object 360 (o360/ver/1). annotator can also ask stor to keep the 
annotator's uuid of the file a500. stor will return to annotator that 

"f105": { "object": "360", "version": "1", "ext": { "uuid": "a500", "app": "annotator" }, "status": "new", "hash": "..." }

the document gets digitally signed, we get f2 => o360/ver/2.

"f105": { "object": "360", "version": "2", "ext": { "uuid": "r303", "app": "signer" }, "status": "new", "hash": "..." }

email bot will get the f105 later from u2 else who also upload f1/v1 to his glued-drive. annotator may reject queing up the
doc for annotation, because stor response will be

"f105": { "object": "360", "version": "1", "ext": { "uuid": "a500", "app": "annotator" }, "status": "exists", "hash": "...", "versions": [..] }

annotator may check if a500 was already processed (because of status exists). u2 may decide to upload the contract to his glued-drive.

- if authorization rules allow full file access to u2 on annotator/industra/*, then his glued-drive gets object o360   
- if authorization rules allow partial file access to u2 on annotator/industra/*, then his glued-drive gets object o360/v2, u2 may request full access on o360
- if authorization rules disallow file access to u2 a new object will be generated with a new auth context.

toto je blby. s tema revizema se to bije s acl. muzeme drzet revize tak ze pokud mas prava na vse, tak pristup na stary objekt udela autoredirect na posledni. plus ti da seznam linked docs.



POST requests to the `files` endpoint will accept
- [REQUIRED] file or files
- [OPTIONAL] AUTH JWT token (users uploading directly to ) or API key
- [OPTIONAL] file metadata
  - external service generated file uuid
  - predecessor uuid
  - change description
  - tags
  - auth context: service, collection, object



 
- 
  - calculates sha hash
  - returns object uuid



Current maximum file size is capped at 8000M and 1800s execution time per nginx/php settings in
- glued/Config/Nginx/sites-enabled/glued-stor
- glued/Config/Nginx/snippets/locations/glued-stor.conf
- glued/Config/Php/99-glued-stor.ini

Nginx is applied automatically.
- TODO: make max upload filesize configurable.

## Testing

```bash
curl -k -X POST https://glued/api/stor/files/v1 -F file=@/home/user/somefile.pdf
curl -k -X POST https://glued/api/stor/files/v1  -H "Content-Type: multipart/form-data" \
     -F "json_data={\"login\":\"my_login\",\"password\":\"my_password\"};type=application/json" \
     -F "file=@/home/killua/Ryanair.pdf"  \
     -F "field1=fiels2"

```

## Example configuration

Under the dgs (devicegroups) and devices keys, unique item keys must be used to avoid mishaps.
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

