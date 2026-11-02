{{-- Run the script that follows on stdin as $sshUser, given its .ssh directory and then $arguments. Site users' files are
     changed as those users, so a link one puts in place of its file cannot make root write elsewhere. --}}
@if ($sshUser === 'root')
bash -s -- /root/.ssh @foreach ($arguments as $argument){!! escapeshellarg($argument) !!} @endforeach<<'SWAGE_EOF'
@else
sudo -n -u '{!! $sshUser !!}' bash -s -- '/home/{!! $sshUser !!}/.ssh' @foreach ($arguments as $argument){!! escapeshellarg($argument) !!} @endforeach<<'SWAGE_EOF'
@endif
