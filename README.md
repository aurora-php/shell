# Shell

Execute commands, supports command chaining; `proc_open` wrapper. Example:

```php
use \Octris\Shell;
use \Octris\Shell\Command;
use \Octris\Shell\StdStream;
use \Octris\Shell\StreamFilter;

$cmd = Command::HandBrakeCli(['-Z', 'Fast 1080p30', '-i', 'inp.mp4', '-o', 'out.mp4'])
    ->setPipe(StdStream::STDOUT, Command::cat()
        ->appendStreamFilter(StdStream::STDOUT, StreamFilter::class, function (string $data = null) {
            if (!is_null($data)) {
                if (preg_match('/^Encoding: .+?([0-9]+(\.[0-9]+))\s*%/', trim($data), $match)) {
                    print "\r" . str_repeat(' ', 50);
                    print "\rprogress: " . $match[1] . "%";
                }
            } else {
                print "\r" . str_repeat(' ', 50);
                print "\rprogress: 100%\n";
            }

            return $data;
        }));

Shell::create($cmd)
    ->exec();
```