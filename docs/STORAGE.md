# TryHackX Files storage

This document explains local file storage, relocation and multi-disk deployments.

## Default layout and integrity

| Directory | Contents | Runtime constant |
|---|---|---|
| `uploads/` | one directory per stored file plus its manifest | `UPLOADS_DIR` |
| `data/` | thumbnails, cache, installer state and default quarantine | `DATA_DIR` |

Both directories are outside `public/`. PHP and Python must resolve exactly the same path.

Every new `uploads/<id>/` contains one named file and `.filehost-storage-v1.json`. The manifest
stores format version, exact name, size and upload-time SHA-256. PHP reads, Python downloads,
collection ZIPs and thumbnails require a manifest consistent with the database rather than
choosing an arbitrary directory entry.

### Deleted-file quarantine

**Administration → Settings → Storage → Deleted-file quarantine** accepts 0–3650 days.
Zero permanently deletes after queue processing. A positive value first copies and verifies the
file directory in `data/file-quarantine/<id>`, records metadata/reason/actor/checksum, publishes
database state and only then removes the active copy.

A dedicated volume may be configured:

```php
define('FILE_QUARANTINE_PATH', '/mnt/recovery/filehost-quarantine');
```

It may not overlap `UPLOADS_DIR` or `public/` after symlink resolution. Include it in backup and
capacity monitoring. Deleting one's own account bypasses the quarantine so the request leaves no
recoverable copy; an account deleted by an administrator is quarantined like any other
administrative delete.

## Relocating uploads

Add paths to `config/config.local.php`:

```php
// Windows
define('UPLOADS_PATH', 'D:/filehost-uploads');

// Linux
define('UPLOADS_PATH', '/mnt/storage/uploads');

// Optional: move thumbnails/cache as well
define('DATA_PATH', '/mnt/storage/data');
```

Absolute paths are used directly. Relative paths resolve from the project root. Forward slashes
work on Windows and Linux.

Migration procedure:

1. stop Python and preferably Apache so writes cannot occur;
2. copy the entire contents while preserving ID directories and manifests;
3. set `UPLOADS_PATH`;
4. grant PHP and Python read/write access; on Debian the upload service is sandboxed with
   `ProtectSystem=strict`, so run `sudo FILEHOST_UNITS_ONLY=1 bash scripts/install-debian.sh`
   after changing `UPLOADS_PATH`, `DATA_PATH` or `FILE_QUARANTINE_PATH`, also when the change was
   made in **Settings → Storage**: it derives the writable paths from `config/config.local.php`,
   writes the systemd drop-in that opens them and restarts the services. A plain restart is not
   enough there: uploads would fail with "read-only file system" while downloads still work;
5. restart the services where step 4 did not, and test an old download plus a new upload;
6. run integrity checks;
7. delete the old copy only after successful verification.

```bash
rsync -aHAX --info=progress2 /var/www/filehost/uploads/ /mnt/storage/uploads/
sudo -u www-data php scripts/check-storage-integrity.php --json
```

Use the service account (`www-data` or your dedicated one) for the maintenance scripts on Linux;
on Windows run them without `sudo -u`.

Windows copy example:

```powershell
robocopy C:\wamp64\www\filehost\uploads D:\filehost-uploads /E /COPYALL
```

Database rows store file IDs, not absolute paths, so no SQL migration is required.

### Legacy objects without manifests

```bash
sudo -u www-data php scripts/check-storage-integrity.php --repair-missing --json
sudo -u www-data php scripts/check-storage-integrity.php --json
```

Repair as the service account: a manifest written as root is private to root, and the very files
it repaired would stop downloading. `--repair-missing` therefore refuses root (the read-only check
does not). Repair creates a manifest only when the database name/size exactly match a regular file
and never follows a symlink. Exit code 0 means valid, 2 means missing/corrupt data, and 1 means the checker
itself failed.

## Multiple disks

TryHackX Files writes to one directory and does not distribute objects across application-managed
roots. Pool disks at the operating-system layer and point `UPLOADS_PATH` at the resulting volume.

Recommended choices:

| Environment | Choice |
|---|---|
| Windows, simple expandable pool | Storage Spaces |
| Linux, existing independent filesystems | mergerfs with `category.create=mfs` |
| Linux, one expandable block volume | LVM |
| Disk-failure resilience | Storage Spaces Mirror/Parity, btrfs or ZFS redundancy |

### Windows Storage Spaces

```powershell
# WARNING: eligible disks added to the pool are erased.
Get-PhysicalDisk -CanPool $true
New-StoragePool -FriendlyName FilePool -StorageSubSystemFriendlyName "Windows Storage*" `
    -PhysicalDisks (Get-PhysicalDisk -CanPool $true)
New-VirtualDisk -StoragePoolFriendlyName FilePool -FriendlyName FileSpace `
    -ResiliencySettingName Mirror -UseMaximumSize
Get-VirtualDisk FileSpace | Get-Disk | Initialize-Disk -PartitionStyle GPT -PassThru |
    New-Partition -AssignDriveLetter -UseMaximumSize |
    Format-Volume -FileSystem NTFS -NewFileSystemLabel Uploads
```

`Simple` maximizes capacity but any single-disk failure can destroy the pool. Mirror/Parity
reduces usable capacity. None of these replaces backup.

### Linux mergerfs

```bash
sudo apt install mergerfs

# /etc/fstab
/mnt/disk1:/mnt/disk2:/mnt/disk3 /mnt/storage fuse.mergerfs \
defaults,allow_other,category.create=mfs,minfreespace=20G,fsname=mergerfs 0 0

sudo mkdir -p /mnt/storage
sudo mount -a
```

Each object remains wholly on one filesystem. `mfs` places new objects on the branch with the
most free space.

### Linux LVM

```bash
sudo pvcreate /dev/sdb /dev/sdc
sudo vgcreate vg_files /dev/sdb /dev/sdc
sudo lvcreate -l 100%FREE -n lv_uploads vg_files
sudo mkfs.ext4 /dev/vg_files/lv_uploads
sudo mount /dev/vg_files/lv_uploads /mnt/storage
```

A linear non-RAID LVM volume can be damaged by one disk failure. Use appropriate redundancy and
tested backups.

## Why distribution is not implemented in PHP/Python

Both runtimes resolve files for downloads, interruptible streams, collection ZIPs, thumbnails, preview,
deletion and accounting. Searching multiple roots would duplicate critical logic and turn a
temporarily unavailable root into ambiguous “missing” files. Operating-system pooling provides
one consistent namespace without that application-level failure mode.

A future remote backend requires a real storage interface, then tested local, S3/MinIO and
possibly Google Drive drivers. It must cover Range, throttling, ZIPs, thumbnails, deletion,
quarantine, migration and rollback. This remains deferred in [ROADMAP.md](ROADMAP.md).

## Capacity controls

- global upload-directory cap and group-level quotas;
- per-group retention, with the global retention value used only as fallback;
- file/collection expiry and download limits;
- quota enforcement grace window;
- optional deleted-file quarantine.

Use the panel for policy and monitor filesystem free space externally.

## Thumbnail resource limits

| Environment variable | Default | Enforced range |
|---|---:|---:|
| `FILEHOST_THUMB_WORKERS` | `2` | 1–4 processes |
| `FILEHOST_THUMB_MAX_INPUT_BYTES` | `268435456` | 1 MiB–1 GiB |
| `FILEHOST_THUMB_MAX_SOURCE_PIXELS` | `40000000` | 1–100 million pixels |

Untrusted media is parsed in a worker process with a 30-second timeout, 16 MiB output cap,
per-ID lock and bounded five-minute negative cache. Failure falls back to an icon and does not
prevent original-file download.

Verify both runtimes resolve the same path:

```bash
php -r 'require "src/config.php"; echo UPLOADS_DIR, "\n";'
venv/bin/python -c "import upload_server as u; print(u.UPLOADS_DIR)"
curl -s http://127.0.0.1:8001/ready
```
