Vagrant.configure("2") do |config|

    config.vm.provider :virtualbox do |_vb, override|
        override.vm.box = "trusty-cloud"
        override.vm.box_url = 'https://cloud-images.ubuntu.com/vagrant/trusty/current/trusty-server-cloudimg-amd64-vagrant-disk1.box'
        override.vm.box_download_insecure = true
        override.vm.network "private_network", ip: "192.168.33.10"
        override.vm.synced_folder ".", "/vagrant", :mount_options => ["dmode=777"]
    end

    config.vm.provider :lxc do |_lxc, override|
        override.vm.box = 'fgrehm/trusty64-lxc'
        override.vm.network :forwarded_port, guest: 80, host_ip: '0.0.0.0', host: 8080, id: 'http'
        override.vm.synced_folder ".", "/vagrant", :mount_options => ["bind", "create=dir"]
    end    

    config.vm.hostname = "relevance"

    config.vm.provision "shell", path: "bootstrap.sh"
end
