# Transparent Class Extension

The [cascading filesystem](files) allows transparent class extension. For instance, the class [Cookie] is defined in `SYSPATH/classes/cookie.php` as:

    class Cookie extends Kohana_Cookie {}

The default Kohana classes, and many extensions, use this definition so that almost all classes can be extended. You extend any class transparently, by defining your own class in `APPPATH/classes/cookie.php` to add your own methods.

[!!] You should **never** modify any of the files that are distributed with Kohana. Always make modifications to classes using transparent extension to prevent upgrade issues.

For instance, if you wanted to create method that sets encrypted cookies using the [Encrypt] class, you would creat a file at `application/classes/cookie.php` that extends Kohana_Cookie, and adds your functions:

    <?php defined('SYSPATH') or die('No direct script access.');

    class Cookie extends Kohana_Cookie {

        /**
         * @var  mixed  default encryption instance
         */
        public static $encryption = 'default';

        /**
         * Sets an encrypted cookie.
         *
         * @uses  Cookie::set
         * @uses  Encrypt::encode
         */
         public static function encrypt($name, $value, $expiration = NULL)
         {
             $value = Encrypt::instance(Cookie::$encrpytion)->encode((string) $value);

             parent::set($name, $value, $expiration);
         }

         /**
          * Gets an encrypted cookie.
          *
          * @uses  Cookie::get
          * @uses  Encrypt::decode
          */
          public static function decrypt($name, $default = NULL)
          {
              if ($value = parent::get($name, NULL))
              {
                  $value = Encrypt::instance(Cookie::$encryption)->decode($value);
              }

              return isset($value) ? $value : $default;
          }

    } // End Cookie

Now calling `Cookie::encrypt('secret', $data)` will create an encrypted cookie which we can decrypt with `$data = Cookie::decrypt('secret')`.

### How it works

To understand how this works, let's look at what happens normally.  When you use the Cookie class, [Kohana::autoload] looks for `classes/cookie.php` in the [cascading filesystem](files).  It looks in `application`, then each module, then `system`. The file is found in `system` and is included.  Of coures, `system/classes/cookie.php` is just an empty class which extends `Kohana_Cookie`.  Again, [Kohana::autoload] is called this time looking for `classes/kohana/cookie.php` which it finds in `system`.

When you add your transparently extended cookie class at `application/classes/cookie.php` this file essentially "replaces" the file at `system/classes/cookie.php` without actually touching it.  This happens because this time when we use the Cookie class [Kohana::autoload] looks for `classes/cookie.php` and finds the file in `application` and includes that one, instead of the one in system.

## Multiple Levels of Extension {#multiple-extensions}

If you are extending a Kohana class in a module, you should maintain transparent extensions. Instead of making the [Cookie] extension extend Kohana, you can create `MODPATH/mymod/encrypted/cookie.php`:

    class Encrypted_Cookie extends Kohana_Cookie {

        // Use the same encrypt() and decrypt() methods as above

    }

And create `MODPATH/mymod/cookie.php`:

    class Cookie extends Encrypted_Cookie {}

This will still allow users to add their own extension to [Cookie] while leaving your extensions intact. However, the next extension of [Cookie] will have to extend `Encrypted_Cookie` instead of `Kohana_Cookie`.