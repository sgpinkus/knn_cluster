# Overview
The script `knn_cluster` can be used for visualizing and getting basic stats on different types of KNN connected component based clusterings of a data set. It's useful for testing and visualizing small 2D and 3D data sets. Also its relatively easy to add new types of clusterings and distance metrics, which makes it useful for experimentation (see the `-c=rules` option argument). I wrote and used this script as part of my CS Honours project which related to types of KNN clustering.

Currently it's slow. The clustering algorithm needs to be rewritten as its `O(n^2)` rather than `O(n*log(n))` as it should be. PHP arrays are also slow which does not help. Significantly faster with [HHVM](https://docs.hhvm.com/hhvm/installation/introduction) PHP interpreter. Not recommended for data sets over 10000 data points.

<img width="50%" src="/docs/plot_rcknn_k020_knn.gif" float="right">

# Requirements

 * All visualization is done via gnuplot (>=4.4). Currently visualization fails silently if gnuplot is not installed.
 * Requires >= PHP 5.3. Fails loudly if not.

# Typical Usage Examples
Get minimal help:

    php ./knn_cluster.php --help

Cluster data file `sampledata/ess/ess.txt` with rcknn (reciporically connected, AKA mutually connected) clustering. Visualize with `knn` type visualization, with a sweep of K values from 0 to k_max. Note by default all output is into the directory of the input file:

    php ./knn_cluster.php -f=sampledata/ess/ess.txt -c=rcknn -v=knn --k_sweep=0

Do the same again, this time cache the result and set k_max explicitly rather than default:

    php ./knn_cluster.php -f=sampledata/ess/ess.txt -c=rcknn -v=knn --k_sweep=0 --k_max=30 --cache

Use a previously cached graph, output stats, but not knn vision. Also output any stats output to stdout as well as file:

    php ./knn_cluster.php -f=sampledata/ess/ess.cache -c=rcknn -s --stdout --k_sweep=0

Use rules to get a KNN clustering. Do points visualization of the output. Note the "rules" option to -c (clustering type) makes "rcknn" and other -c option values obselete.
Conditions Like RC are specified by `-c=rules --co_rules=rcknn`:

    php ./knn_cluster.php -f=sampledata/ess/ess.cache -c=rules -v=points --k_sweep=0

Density related like clustering with RCKNN too. Set alpha option to density related measure:

    php ./knn_cluster.php -f=sampledata/ess/ess.cache -c=rules --co_rules=rcknn,dense --co_dense_alpha=1.2 -v=points --k_sweep=0
