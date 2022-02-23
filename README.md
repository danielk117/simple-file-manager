simple-file-manager
===================

This is a fork of the PHP based simple-file-manager from @jcampbell1, including the edit feature from @diego95root.

The code is a single php file. Just copy `index.php` to a folder on your webserver and get started.

## Why it is good

- Single file, there are no images, or css folders
- One optional config file or just keep the default settings
- Ajax based so it is fast, but doesn't break the back button
- Allows drag and drop file uploads if the folder is writable by the webserver (`chmod 777 your/folder`)
- Works with Unicode file names
- The interface is usable from an iPad
- XSRF protection, and an optional password.

## Do not allow uploads on the public web

If you allow uploads on the public web, it is only a matter of time before your server is hosting and serving very illegal content. Any of the following options will prevent this:
 - Don't make the folder writable by the webserver `chmod 775`
 - Set `$allow_upload = false`
 - Use a password `$PASSWORD = 'some password'`
 - Use a `.htaccess` file with Apache, or `auth_basic` for nginx
 - Only use this on a private network

## Screenshot

![Screenshot](https://raw.github.com/jcampbell1/simple-file-manager/master/screenshot.png "Screenshot")
