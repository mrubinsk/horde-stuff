<?php
/**
 * Utility to manage the process of splitting the monolithic Horde repository,
 * and setup a working submodule repository.
 */
require_once 'Horde/Autoloader/Default.php';

$parser = new Horde_Argv_Parser(
    array(
        'usage' => "%prog ACTION\n\t[--command=PATH]\n\t[--original=PATH]\n\t[--split_repo=PATH]\n\t[--monolithic=PATH]\n\t[--tmp=PATH]
ACTION
Selects the action to perform.
  split - Splits the legacy monolithic repository.
  create - Creates a new working repository using git subtree from the master branch of all locally checked out modules.",
        'optionList' => array(
            new Horde_Argv_Option('-c', '--command', array(
                'action' => 'store',
                'help' => 'Path to horde-git-split.',
                'dest' => 'command'
            )),
            new Horde_Argv_Option('-o', '--original', array(
                'action' => 'store',
                'help' => 'Path to the legacy monolithic repository.',
                'dest' => 'original')),
            new Horde_Argv_Option('', '--split_repo', array(
                'action' => 'store',
                'help' => 'Directory containing repositories of all split modules.',
                'dest' => 'split_repo')),
            new Horde_Argv_Option('-m', '--monolithic', array(
                'action' => 'store',
                'help' => 'The location of the new, local submodule/monolithic repository.',
                'dest' => 'monolithic')),
            new Horde_Argv_Option('-t', '--tmp', array(
                'action' => 'store',
                'help' => 'Temporary directory for git operations.',
                'dest' => 'tmp'))
        )
    )
);
list($options, $argv) = $parser->parseArgs();
if (empty($argv[0])) {
    $parser->printHelp();
    die;
}

switch ($argv[0]) {
case 'split':
    doSplit();
    moveSplit();
    break;
case 'create':
    if (empty($options['monolithic']) || empty($options['split_repo'])) {
        print "Missing '--monolithic' and/or '--split_repo' option.\n";
        $parser->printHelp();
        die;
    }
    doSubtreeMaster();
    break;
default:
    $parser->printHelp();
}

function doSplit()
{
    global $options;

    // Ignore these framework packages/folders for now.
    $fw_ignore = array('bin', 'xxhash', 'lz4');

    // $apps = array('ansel', 'beatnik', 'chora', 'horde', 'imp', 'components', 'content', 'gollem', 'hermes', 'ingo',
    //               'jonah', 'kolab', 'koward', 'kronolith', 'mnemo', 'nag', 'passwd', 'pastie', 'sam', 'sesha',
    //               'timeobjects', 'trean', 'turba', 'ulaform', 'whups', 'wicked');
    $apps = array();

    // Use the system tmpdir to build the split repositories.
    $tmp = !empty($options['tmp']) ? $options['tmp'] : sys_get_temp_dir();

    // Create split repository
    foreach ($apps as $app) {
        passthru("{$options['command']} -c {$options['original']}/$app -t $tmp -o $tmp");
    }
    $dir = new DirectoryIterator($options['original'] . '/framework');
    foreach ($dir as $fi) {
        if ($fi->isDir() && !$fi->isDot() && !in_array($fi->getFilename(), $fw_ignore)) {
            passthru("{$options['command']} -c {$fi->getPathname()} -t $tmp -o $tmp");
            // test
            break;
        }
    }
}

function moveSplit()
{
    global $options;

    // Move the newly created split repos to canonical paths.
    $dir = new DirectoryIterator($tmp);
    foreach ($dir as $fi) {
        if ($fi->isDir()) {
            $pattern = '/^[0-9]+_(\w+)/';
            if (preg_match($pattern, $fi->getFilename(), $matches)) {
    	        if (in_array($fi->getFilename(), $apps)) {
    		        $file_name = $matches[1];
                } else {
                    $file_name = 'Horde_' . $matches[1];
    	        }
                $new_pathname =  $options['split_repo'] . '/' .  $file_name;

                // Can't use rename() since we might be copying across filesystems
                // like e.g., from a RAM disk to physical disk.
                // See: https://bugs.php.net/bug.php?id=54097
                //rename($fi->getPathname(), $new_pathname);
                passthru("mv '{$fi->getPathname()}' '$new_pathname'");
                $inner = new DirectoryIterator($new_pathname . '/split');
                foreach ($inner as $file) {
                    if (!$file->isDot()) {
                        rename($file->getPathname(), $new_pathname . '/' . $file->getFilename());
                    }
                }
                print("Deleting $new_pathname/split.\n");
                rmdir($new_pathname . '/split');
    	    }
        }
    }
}

function doSubtreeMaster()
{
    global $options;

    // Create the new monolithic repo, and create at least one commit for subtree to work.
    passthru("mkdir '{$options['monolithic']}';cd '{$options['monolithic']}'; pwd; git init; touch first.txt; git add first.txt; git commit -m 'First commit.'");
    addSubtree('Horde_ActiveSync', $options['split_repo'], 'master');
}

function addSubtree($module, $remote, $branch)
{
    global $options;
    passthru("cd {$options['monolithic']}; git remote add -f '$module' '$remote/$module'");
    passthru("cd {$options['monolithic']}; git subtree add --prefix='$module' '$module' '$branch' --squash");
    passthru("cd {$options['monolithic']}; git subtree split --prefix='$module' --annotate='(split)' --branch '$branch'");
}


