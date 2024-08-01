# OVERVIEW
`knn_cluster.py` does a KNN clustering of data and outputs it to stdout. Supports pluggable custom distance measures and additional same-cluster rules.

# INSTALLATION

    python -m pip install git@github.com:sgpinkus/knn_cluster.git

# USAGE
See options:

    python -m knn_cluster cluster --help

Run with default config (uses sample data):

    python -m knn_cluster cluster

# CONFIGURATION
Configuration is via a python config file (see example in [./config.py](./config.py). Certain config vars can be overridden from the command line.

# VISUALIZATION
`plot.py` is a basic script to vizualize output of `knn_cluster.py`.

# TODO
[TODO](./TODO.md)
